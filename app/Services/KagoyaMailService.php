<?php

namespace App\Services;

use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\RescoreJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class KagoyaMailService
{
    private $socket;
    private int $tagSeq = 0;

    /**
     * KAGOYA IMAP から直接メールを取得し emails テーブルに保存する
     *
     * UID-based incremental fetch:
     * 前回取込済の最大 UID + 1 から先頭 (`UID FETCH last+1:*`) を取得することで、
     * シーケンス番号窓 (旧 `FETCH exists-99:exists`) で発生していた構造的取りこぼしを排除する。
     * 1回の sync は $budgetSeconds で打ち切り、残りは次回 sync が引き取る (UID 順保証)。
     */
    public function syncEmails(int $budgetSeconds = 100): int
    {
        $startTime = microtime(true);
        $tenantId = 1;

        if (!$this->connect()) {
            return 0;
        }

        try {
            // EXAMINE INBOX（読み取り専用 — \Recentフラグに影響しない）
            $selectResp = $this->imapCommand('EXAMINE INBOX');
            $exists = 0;
            foreach ($selectResp['lines'] as $line) {
                if (preg_match('/\*\s+(\d+)\s+EXISTS/', $line, $m)) {
                    $exists = (int) $m[1];
                }
            }
            Log::info("[KagoyaIMAP] INBOX: {$exists}件");

            if ($exists === 0) return 0;

            // 前回最終取込 UID を emails から動的算出 (state テーブル不要)
            $lastUid = (int) (Email::where('tenant_id', $tenantId)
                ->whereRaw("gmail_message_id ~ '^imap-[0-9]+$'")
                ->selectRaw("COALESCE(MAX(CAST(SUBSTRING(gmail_message_id FROM 6) AS BIGINT)), 0) AS v")
                ->value('v'));

            // 初回 (last_uid=0) は最新100件のみ取得して取込爆発を防ぐ。
            // 2回目以降は UID-based incremental fetch で「未取込分のみ」を全件取得。
            if ($lastUid === 0) {
                $start = max(1, $exists - 99);
                $fetchResp = $this->imapCommand("FETCH {$start}:{$exists} (UID)");
            } else {
                $fetchResp = $this->imapCommand("UID FETCH " . ($lastUid + 1) . ":* (UID)");
            }

            $uids = [];
            foreach ($fetchResp['lines'] as $line) {
                if (preg_match('/UID\s+(\d+)/', $line, $m)) {
                    $uids[] = (int) $m[1];
                }
            }
            // IMAP の UID 順は通常昇順だが、budget timeout 後の安全な再開のため明示ソート
            sort($uids);

            // 既存 UID を一括チェック (並行 sync / race condition の保険)
            $existingUids = Email::where('tenant_id', $tenantId)
                ->whereIn('gmail_message_id', array_map(fn($u) => "imap-{$u}", $uids))
                ->pluck('gmail_message_id')
                ->map(fn($id) => (int) substr($id, 5))
                ->toArray();

            $newUids = array_values(array_diff($uids, $existingUids));

            if (empty($newUids)) {
                Log::info("[KagoyaIMAP] 新着なし", ['last_uid' => $lastUid, 'exists' => $exists]);
                return 0;
            }

            Log::info("[KagoyaIMAP] 新着候補", ['count' => count($newUids), 'last_uid' => $lastUid]);

            $stored = 0;
            $processed = 0;
            $deferred = 0;
            // UID FETCH で本文取得（一件ずつ）
            foreach ($newUids as $uid) {
                if (microtime(true) - $startTime > $budgetSeconds) {
                    $deferred = count($newUids) - $processed;
                    Log::info("[KagoyaIMAP] budget timeout, 次回 sync で再開", ['deferred' => $deferred]);
                    break;
                }
                $processed++;
                try {
                    $result = $this->fetchMessageByUid($uid);
                    if (empty($result['body'])) continue;

                    if ($this->storeRawMessage($result['body'], $tenantId, "imap-{$uid}", $result['internaldate'])) {
                        $stored++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[KagoyaIMAP] UID {$uid} 処理失敗: " . $e->getMessage());
                    continue;
                }
            }

            Log::info("[KagoyaIMAP] 同期完了", ['stored' => $stored, 'deferred' => $deferred]);
            return $stored;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * outsource@ 宛の取込済みメールを Kagoya サーバーから削除する（容量/同期負荷対策）。
     *
     * 削除対象は DB 側で厳密に定義する:
     *   gmail_message_id='imap-{UID}'(=取込済み) かつ to_address に $address を含み received_at < $before。
     * その UID 集合のうち「サーバーに実在する古いメール」だけを \Deleted + EXPUNGE する。
     * DB の emails 行は削除しない（表示・スコアは取込済みデータで維持）。
     * 削除済み UID は < lastUid なので UID 増分同期には影響しない。冪等（再実行で残りを削除）。
     *
     * @return array{db_target:int,server_old:int,deletable:int,flagged:int,expunged:int,exists_before:?int,exists_after:?int,execute:bool}
     */
    public function purgeOutsourceMail(string $before, string $address, bool $execute, int $limit): array
    {
        $dbUids = Email::where('tenant_id', 1)
            ->whereRaw("gmail_message_id ~ '^imap-[0-9]+$'")
            ->where('to_address', 'like', '%' . $address . '%')
            ->where('received_at', '<', $before)
            ->selectRaw('CAST(SUBSTRING(gmail_message_id FROM 6) AS BIGINT) AS uid')
            ->orderBy('uid')
            ->pluck('uid')
            ->map(fn ($u) => (int) $u)
            ->all();

        $stats = [
            'db_target' => count($dbUids), 'server_old' => 0, 'deletable' => 0,
            'flagged' => 0, 'expunged' => 0, 'exists_before' => null, 'exists_after' => null, 'execute' => $execute,
        ];
        if (empty($dbUids)) {
            return $stats;
        }

        if (!$this->connect()) {
            throw new \RuntimeException('Kagoya IMAP 接続失敗');
        }
        try {
            $sel = $this->imapCommand('SELECT INBOX'); // read-write
            if (!$sel['ok']) {
                throw new \RuntimeException('SELECT INBOX 失敗: ' . $sel['line']);
            }
            $stats['exists_before'] = $this->parseExistsCount($sel['lines']);

            $cap = $this->imapCommand('CAPABILITY');
            $uidplus = false;
            foreach ($cap['lines'] as $l) {
                if (stripos($l, 'UIDPLUS') !== false) $uidplus = true;
            }
            $stats['uidplus'] = $uidplus;

            // サーバー側の「before + 3日バッファ」より古い UID（INTERNALDATE vs Date ヘッダのズレ吸収。DB が日付の正）
            $imapDate = Carbon::parse($before)->addDays(3)->format('d-M-Y');
            $search = $this->imapCommand("UID SEARCH BEFORE {$imapDate}");
            $serverUids = [];
            foreach ($search['lines'] as $l) {
                if (preg_match('/^\*\s+SEARCH\s+(.+)$/i', $l, $m)) {
                    foreach (preg_split('/\s+/', trim($m[1])) as $n) {
                        if ($n !== '') $serverUids[(int) $n] = true;
                    }
                }
            }
            $stats['server_old'] = count($serverUids);

            $deletable = array_values(array_filter($dbUids, fn ($u) => isset($serverUids[$u])));
            $stats['deletable'] = count($deletable);

            if (!$execute) {
                return $stats; // DRY-RUN
            }

            $toProcess = array_slice($deletable, 0, $limit);
            foreach (array_chunk($toProcess, 500) as $chunk) {
                $set = implode(',', $chunk);
                $store = $this->imapCommand("UID STORE {$set} +FLAGS.SILENT (\\Deleted)");
                if ($store['ok']) {
                    $stats['flagged'] += count($chunk);
                    if ($uidplus) {
                        $exp = $this->imapCommand("UID EXPUNGE {$set}");
                        if ($exp['ok']) $stats['expunged'] += count($chunk);
                    }
                }
            }
            if (!$uidplus && $stats['flagged'] > 0) {
                // UIDPLUS 非対応: \Deleted 全体を一括 EXPUNGE（このメールボックスは取込専用で他に \Deleted を付けないため安全）
                if ($this->imapCommand('EXPUNGE')['ok']) {
                    $stats['expunged'] = $stats['flagged'];
                }
            }

            $sel2 = $this->imapCommand('SELECT INBOX');
            $stats['exists_after'] = $this->parseExistsCount($sel2['lines']);
            return $stats;
        } finally {
            $this->disconnect();
        }
    }

    private function parseExistsCount(array $lines): int
    {
        foreach ($lines as $l) {
            if (preg_match('/\*\s+(\d+)\s+EXISTS/', $l, $m)) return (int) $m[1];
        }
        return 0;
    }

    /**
     * @return bool true=正常取込／false=バウンスstub保存(再処理スキップ用)
     */
    private function storeRawMessage(string $raw, int $tenantId, string $uid, ?string $internalDate = null): bool
    {
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerBlock = $parts[0] ?? '';
        $bodyRaw = $parts[1] ?? '';

        $headers = $this->parseHeaders($headerBlock);

        $subject = $this->decodeHeader($headers['subject'] ?? '(件名なし)');
        $from = $this->decodeHeader($headers['from'] ?? '');
        $to = $this->decodeHeader($headers['to'] ?? '');
        // RFC822 Message-ID（< > 込み）。SelfMailsView 返信時の In-Reply-To 用。
        // header line には複数 token 入る可能性があるため最初の <...> のみ抽出。
        $rfcMessageId = null;
        if (!empty($headers['message-id'])) {
            if (preg_match('/<([^>]+)>/', $headers['message-id'], $mm)) {
                $rfcMessageId = mb_substr(trim($mm[1]), 0, 998);
            }
        }

        [$fromName, $fromAddress] = $this->parseFrom($from);

        // Date ヘッダー（送信者メーラーの送信時刻）を優先、なければ INTERNALDATE。
        // Kagoya 配送遅延時に INTERNALDATE が大きく後ろにズレるため (例: 送信10:37→取込19:29)、
        // メーラー上の見え方と一致する Date ヘッダーを優先する。改竄リスクはあるが UX を優先。
        $receivedAt = ($headers['date'] ?? null)
            ? Carbon::parse($headers['date'])->utc()
            : ($internalDate
                ? Carbon::parse($internalDate)->utc()
                : Carbon::now()->utc());

        // 到着時刻 = INTERNALDATE (Kagoya メールボックス着信時刻 = webmail 表示と一致)。
        // 一覧の並び/「受信」表示に使う。Kagoya 配送遅延があっても webmail と同じ鮮度になる。
        // INTERNALDATE が取れない場合は received_at(送信時刻)で代替。
        $arrivedAt = $internalDate
            ? Carbon::parse($internalDate)->utc()
            : $receivedAt;

        // バウンスメール（不達通知）/ 上流スパム判定済みメールは category='bounce' の
        // 最小 stub だけ保存し、本文/添付処理はスキップ。
        // 旧実装は何も保存しなかったため、毎回 IMAP から同じ UID が「新着」として返り
        // CPU と Kagoya API を浪費していた (dedup 用 anchor の役割を兼ねる)。
        $lcFrom     = strtolower($fromAddress);
        $lcFromName = strtolower($fromName ?? '');
        $lcSubject  = strtolower($subject);
        $isBounceFrom = str_contains($lcFrom, 'mailer-daemon')
            || str_contains($lcFrom, 'postmaster')
            || str_contains($lcFromName, 'postmaster')
            || str_contains($lcFromName, 'mailer-daemon')
            || str_contains($lcFromName, 'mail delivery');
        $trimmedSubject = trim($lcSubject);
        $isBounceSubject = str_starts_with($trimmedSubject, '[spam]')
            || str_starts_with($trimmedSubject, 'rejected:')
            || str_contains($lcSubject, 'undelivered')
            || str_contains($lcSubject, 'returned mail')
            || str_contains($lcSubject, 'delivery status')
            || str_contains($lcSubject, 'undeliverable')
            || str_contains($lcSubject, 'failure notice')
            || str_contains($lcSubject, 'mail delivery failed')
            || str_contains($lcSubject, 'quota exceeded');
        if ($isBounceFrom || $isBounceSubject) {
            $bounceEmail = Email::create([
                'tenant_id'        => $tenantId,
                'gmail_message_id' => $uid,
                'rfc_message_id'   => $rfcMessageId,
                'thread_id'        => null,
                'subject'          => mb_substr($subject, 0, 255),
                'from_address'     => $fromAddress,
                'from_name'        => $fromName,
                'to_address'       => mb_substr($to, 0, 500),
                'body_text'        => null,
                'body_html'        => null,
                'received_at'      => $receivedAt,
                'arrived_at'       => $arrivedAt,
                'is_read'          => true,    // 未読カウントを汚さない
                'category'         => 'bounce', // classifyPending(whereNull) に拾わせない
                'classified_at'    => $receivedAt,
            ]);

            // ハードバウンス自動抑制: DSN 本文から 5.x.x の失敗宛先を抽出して
            // delivery_addresses を無効化する(config で log-only/enforce 切替)。
            // 解析失敗が取込全体を壊さないよう try/catch で握りつぶす。
            try {
                app(BounceSuppressionService::class)->suppressHardBounces($raw, $tenantId, $bounceEmail->id);
            } catch (\Throwable $e) {
                Log::warning('[BounceSuppression] 解析失敗', ['uid' => $uid, 'error' => $e->getMessage()]);
            }

            return false;
        }

        // 自社ドメイン loop-back / 社員宛て返信は category='self' で保存して案件/技術者リストから除外する。
        //  - from が自社ドメイン (= 自分が配信したメールが Kagoya に戻ってくる)
        //  - to が 自社社員 (outsource@ 以外の @aizen-sol.co.jp 宛て・BP からの返信)
        // バウンスと違って本文/添付には価値がある (proposal thread の reply 紐づけや
        // BP からの追加情報) ので、stub ではなく通常処理を続行し、Email::create 時点で
        // category='self', is_read=true を強制適用する。
        $selfDomain      = $this->extractDomainLower(config('mail.from.address'));
        $selfFromAddress = strtolower(trim((string) config('mail.from.address')));
        $fromDomain      = $this->extractDomainLower($fromAddress);
        $lcTo            = strtolower($to);

        $isSelfFrom = $selfDomain && $fromDomain === $selfDomain;
        $isInternalTo = $selfDomain
            && str_contains($lcTo, '@' . $selfDomain)
            && ($selfFromAddress === '' || !str_contains($lcTo, $selfFromAddress));
        $forceSelf = $isSelfFrom || $isInternalTo;

        $contentType = $headers['content-type'] ?? 'text/plain';
        $cte = strtolower($headers['content-transfer-encoding'] ?? '7bit');
        [$bodyText, $bodyHtml, $attachments] = $this->parseBody($bodyRaw, $contentType, $cte);

        // Kagoya 配送遅延への対処: 「全て既読」を押した後に取込された received_at が押下時点より
        // 古いメール (= 押下時点で既に送信されていたが Kagoya 内で滞留していたメール) は、
        // 既読として取り込む。これにより 12 時間前のメールが取込遅延で未読として残り続ける
        // バグを解消する ([[project_kagoya_gmail_delivery]] の派生)。
        $alreadyMarkedRead = $this->shouldImportAsRead($tenantId, $receivedAt);

        $email = Email::create([
            'tenant_id'        => $tenantId,
            'gmail_message_id' => $uid,
            'rfc_message_id'   => $rfcMessageId,
            'thread_id'        => null,
            'subject'          => mb_substr($subject, 0, 255),
            'from_address'     => $fromAddress,
            'from_name'        => $fromName,
            'to_address'       => mb_substr($to, 0, 500),
            'body_text'        => $bodyText,
            'body_html'        => $bodyHtml,
            'received_at'      => $receivedAt,
            'arrived_at'       => $arrivedAt,
            // self の場合は未読カウントを汚さず classifyPending(whereNull) にも拾わせない
            // forceSelf 以外でも、ユーザーが直近 markAllRead を実行済 (received_at < finished_at) なら既読扱い
            'is_read'          => $forceSelf || $alreadyMarkedRead,
            'category'         => $forceSelf ? 'self' : null,
            'classified_at'    => $forceSelf ? $receivedAt : null,
        ]);

        foreach ($attachments as $i => $att) {
            // EmailAttachment を先に作成して id を取得し、path に埋め込んで
            // 同一メール内で同名添付 (unknown.bin / attachment.pdf 等) が衝突するのを防ぐ。
            // part_index は parseBody の MIME walk 順 (深さ優先) を保存し、IMAP 再取得時の
            // 安定キーとして使う。
            $record = EmailAttachment::create([
                'email_id'            => $email->id,
                'filename'            => $att['filename'],
                'mime_type'           => $att['mime_type'],
                'size'                => $att['size'],
                'gmail_attachment_id' => null,
                'storage_path'        => null,
                'part_index'          => $i,
            ]);

            if (!empty($att['binary'])) {
                try {
                    $ext  = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION)) ?: 'bin';
                    $base = preg_replace('/[^\w\-\.]/u', '_', pathinfo($att['filename'], PATHINFO_FILENAME));
                    $base = preg_replace('/[^\x00-\x7F]/u', '', $base) ?: substr(md5($att['filename']), 0, 8);
                    $path = "attachments/{$tenantId}/{$email->id}/{$record->id}_{$base}.{$ext}";
                    $storage = app(\App\Services\SupabaseStorageService::class);
                    $storagePath = $storage->uploadBinary($att['binary'], $path, $att['mime_type']);
                    if ($storagePath) {
                        $record->update(['storage_path' => $storagePath]);
                    }
                } catch (\Throwable $e) {
                    Log::warning("[KagoyaIMAP] 添付Storage保存失敗: {$att['filename']}: " . $e->getMessage());
                }
            }
        }

        // 返信紐づけ
        // 注意: スケジュールジョブから呼ばれるため Auth context が無く TenantScope が効かない。
        // cross-tenant 誤紐付け (同じ ses_message_id が他テナントに存在した場合の誤発火) を
        // 防ぐため明示的に tenant_id WHERE を付与する (docs/730 #32)。
        $inReplyTo = trim($headers['in-reply-to'] ?? '');
        $history = null;

        // ① In-Reply-To → ses_message_id 完全一致
        if ($inReplyTo) {
            $history = DeliverySendHistory::where('tenant_id', $tenantId)
                ->where('ses_message_id', $inReplyTo)
                ->whereNull('reply_email_id')
                ->first();

            // ② < > 除去してのフォールバック
            if (!$history) {
                $clean = trim($inReplyTo, '<>');
                $history = DeliverySendHistory::where('tenant_id', $tenantId)
                    ->where('ses_message_id', 'like', "%{$clean}%")
                    ->whereNull('reply_email_id')
                    ->first();
            }
        }

        // ③ 差出人メール + 件名（Re:除去）で最新の送信履歴を探す
        // ただし from が自社ドメインの場合は、一斉配信の自己 BCC コピー(from=to=outsource@)が
        // 自分自身の DSH に「返信」として紐付く False Positive を起こすためスキップ。
        // 客先からの本物の返信は from が外部ドメインなのでガードに掛からない。
        if (!$history && $fromAddress && !$isSelfFrom) {
            $originalSubject = trim(preg_replace('/^(Re:\s*|RE:\s*|Fwd:\s*|FW:\s*)*/iu', '', $subject));
            if ($originalSubject) {
                $history = DeliverySendHistory::where('tenant_id', $tenantId)
                    ->where('email', $fromAddress)
                    ->whereNull('reply_email_id')
                    ->where('status', 'sent')
                    ->whereHas('campaign', fn($q) => $q->where('subject', 'like', '%' . $originalSubject . '%'))
                    ->latest()
                    ->first();
                if ($history) {
                    Log::info("[KagoyaIMAP] フォールバック紐づけ(件名+差出人) history_id={$history->id} email_id={$email->id}");
                }
            }
        }

        if ($history) {
            $history->update([
                'reply_email_id' => $email->id,
                'replied_at'     => $email->received_at,
                'status'         => 'replied',
            ]);
            if ($history->campaign_id) {
                DeliveryCampaign::where('id', $history->campaign_id)
                    ->increment('replied_count');
            }
            Log::info("[KagoyaIMAP] 返信紐づけ完了 history_id={$history->id} email_id={$email->id}");
        }

        return true;
    }

    /**
     * 直近完了した markAllRead ジョブの finished_at と received_at を比較し、
     * 取込遅延メールを自動既読化すべきか判定する。
     *
     * Kagoya 配送遅延で 12 時間前送信のメールが今 (取込タイミング) に流れてくるが、
     * ユーザーが既に「全て既読」を実行済 (= 過去の未読を消化済) なら、それより
     * 古い received_at のメールは新着扱いせず既読として取り込む。
     */
    private function shouldImportAsRead(int $tenantId, ?Carbon $receivedAt): bool
    {
        if (!$receivedAt) return false;

        // tenant の最新完了 markAllRead を取得 (キャッシュ 60s で吸収)
        $finishedAt = \Illuminate\Support\Facades\Cache::remember(
            "kagoya:last_mark_all_read:{$tenantId}",
            60,
            function () use ($tenantId) {
                return RescoreJob::where('type', RescoreJob::TYPE_MARK_READ)
                    ->where('tenant_id', $tenantId)
                    ->where('status', RescoreJob::STATUS_COMPLETED)
                    ->orderByDesc('finished_at')
                    ->value('finished_at');
            }
        );

        if (!$finishedAt) return false;

        return $receivedAt->lt(Carbon::parse($finishedAt));
    }

    // ── パース系 ────────────────────────────────────────

    /** メールアドレスから @ 以降を小文字で取り出す。null/不正は null。 */
    private function extractDomainLower(?string $email): ?string
    {
        if (!$email) return null;
        $at = strrpos($email, '@');
        if ($at === false) return null;
        $d = strtolower(trim(substr($email, $at + 1)));
        return $d !== '' ? $d : null;
    }

    private function parseHeaders(string $headerBlock): array
    {
        $headerBlock = preg_replace('/\r?\n[\t ]+/', ' ', $headerBlock);
        $headers = [];
        foreach (explode("\n", $headerBlock) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $key = strtolower(trim(substr($line, 0, $pos)));
            $val = trim(substr($line, $pos + 1));
            $headers[$key] = $val;
        }
        return $headers;
    }

    /**
     * MIME タイプから拡張子（ピリオド込み、例: ".xlsx"）を返す。
     * 不明な場合は ".bin"。filename 復元時の最終フォールバックとして使う。
     */
    public static function extensionForMime(?string $mime): string
    {
        $mime = strtolower(trim((string) $mime));
        $map = [
            'application/pdf'                                                                => '.pdf',
            'application/zip'                                                                => '.zip',
            'application/x-zip-compressed'                                                   => '.zip',
            'application/vnd.ms-excel'                                                       => '.xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'              => '.xlsx',
            'application/msword'                                                             => '.doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'        => '.docx',
            'application/vnd.ms-powerpoint'                                                  => '.ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'      => '.pptx',
            'image/png'                                                                      => '.png',
            'image/jpeg'                                                                     => '.jpg',
            'image/gif'                                                                      => '.gif',
            'image/webp'                                                                     => '.webp',
            'image/svg+xml'                                                                  => '.svg',
            'text/plain'                                                                     => '.txt',
            'text/csv'                                                                       => '.csv',
            'text/html'                                                                      => '.html',
            'application/json'                                                               => '.json',
            'application/xml'                                                                => '.xml',
            'text/xml'                                                                       => '.xml',
            'application/x-7z-compressed'                                                    => '.7z',
            'application/x-rar-compressed'                                                   => '.rar',
            'application/vnd.rar'                                                            => '.rar',
            'application/vnd.ms-excel.sheet.macroenabled.12'                                 => '.xlsm',
            'application/vnd.oasis.opendocument.text'                                        => '.odt',
            'application/vnd.oasis.opendocument.spreadsheet'                                 => '.ods',
        ];
        return $map[$mime] ?? '.bin';
    }

    private function decodeHeader(string $value): string
    {
        if (empty($value) || !str_contains($value, '=?')) return $value;

        // 同一charset+encodingの連続エンコードワードをグループ化してデコード
        return preg_replace_callback(
            '/(=\?([^?]+)\?(B|Q)\?([^?]*)\?=)(\s+=\?\2\?\3\?([^?]*)\?=)*/i',
            function ($m) {
                $charset  = $m[2];
                $encoding = strtoupper($m[3]);
                preg_match_all('/=\?[^?]+\?(B|Q)\?([^?]*)\?=/i', $m[0], $all);
                $parts = $all[2];

                if ($encoding === 'Q') {
                    $decoded = quoted_printable_decode(str_replace('_', ' ', implode('', $parts)));
                } else {
                    $decoded = implode('', array_map('base64_decode', $parts));
                }
                return @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;
            },
            $value
        );
    }

    /**
     * 添付ファイル名の抽出（MIME encoded-word / RFC 5987 / RFC 2231 連結に対応）
     *
     * 対応する Content-Disposition の形式:
     *   filename="=?utf-8?B?...?="                  ← MIME encoded-word
     *   filename*=UTF-8''Y.S%5F%E3%82%B9...         ← RFC 5987 単行
     *   filename*0*=UTF-8''Y.S%5F
     *   filename*1*=%E3%82%B9...xlsx                ← RFC 2231 連結
     *   filename="plain.txt"                        ← シンプル
     * フォールバックで Content-Type: name= も見る。
     */
    private function parseAttachmentFilename(string $partDisp, string $partCt): ?string
    {
        // 1. RFC 2231 連結形式: filename*0(*)= , filename*1(*)= ...
        if (preg_match_all(
            '/filename\*(\d+)(\*?)=\s*("[^"]*"|[^;\r\n]+)/i',
            $partDisp,
            $mm,
            PREG_SET_ORDER
        ) && count($mm) > 0) {
            $parts = [];
            $charset = null;
            $anyEncoded = false;
            foreach ($mm as $m) {
                $idx     = (int) $m[1];
                $encoded = ($m[2] === '*');
                $val     = trim($m[3], "\" \t");
                // 先頭で charset'lang'value を含むのは encoded フラグ付きの最初の要素のみ
                if ($encoded && $charset === null && preg_match("/^([^']*)'([^']*)'(.*)$/", $val, $cm)) {
                    $charset = $cm[1] !== '' ? $cm[1] : 'UTF-8';
                    $val = $cm[3];
                }
                if ($encoded) $anyEncoded = true;
                $parts[$idx] = ['encoded' => $encoded, 'value' => $val];
            }
            ksort($parts);
            $assembled = '';
            foreach ($parts as $p) {
                $assembled .= $p['encoded'] ? urldecode($p['value']) : $p['value'];
            }
            if ($anyEncoded && $charset !== null) {
                return @mb_convert_encoding($assembled, 'UTF-8', $charset) ?: $assembled;
            }
            return $assembled;
        }

        // 2. RFC 5987 単行: filename*=charset'lang'value
        if (preg_match(
            "/filename\*=\s*([A-Za-z0-9\-]+)'([^']*)'([^;\r\n]+)/i",
            $partDisp,
            $m
        )) {
            $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
            $value   = urldecode(trim($m[3], "\" \t"));
            return @mb_convert_encoding($value, 'UTF-8', $charset) ?: $value;
        }

        // 3. シンプルな filename="..." or filename=...
        if (preg_match('/filename=\s*("([^"]*)"|([^;\r\n]+))/i', $partDisp, $m)) {
            $raw = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
            return $this->decodeHeader(trim($raw));
        }

        // 4. Content-Type: name= フォールバック
        if (preg_match('/name=\s*("([^"]*)"|([^;\r\n]+))/i', $partCt, $m)) {
            $raw = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
            return $this->decodeHeader(trim($raw));
        }

        return null;
    }

    private function parseFrom(string $from): array
    {
        if (preg_match('/^(.*?)\s*<(.+?)>$/', $from, $m)) {
            return [trim($m[1], '"\''), $m[2]];
        }
        return [null, $from];
    }

    private function parseBody(string $bodyRaw, string $contentType, string $cte = '7bit'): array
    {
        $text = null;
        $html = null;
        $attachments = [];
        $ct = strtolower($contentType);

        if (str_contains($ct, 'multipart/')) {
            if (preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $m)) {
                $boundary = $m[1];
                $parts = explode("--{$boundary}", $bodyRaw);
                array_shift($parts);

                foreach ($parts as $part) {
                    $part = ltrim($part, "\r\n");
                    if (str_starts_with($part, '--')) break;

                    $subParts = preg_split('/\r?\n\r?\n/', $part, 2);
                    $partHeaders = $this->parseHeaders($subParts[0] ?? '');
                    $partBody = $subParts[1] ?? '';
                    $partCt = $partHeaders['content-type'] ?? 'text/plain';
                    $partCte = strtolower($partHeaders['content-transfer-encoding'] ?? '7bit');
                    $partDisp = $partHeaders['content-disposition'] ?? '';

                    if (str_contains(strtolower($partCt), 'multipart/')) {
                        [$t, $h, $a] = $this->parseBody($partBody, $partCt, $partCte);
                        if ($t && !$text) $text = $t;
                        if ($h && !$html) $html = $h;
                        $attachments = array_merge($attachments, $a);
                        continue;
                    }

                    if (str_contains(strtolower($partDisp), 'attachment') ||
                        (str_contains(strtolower($partDisp), 'filename') && !str_contains(strtolower($partCt), 'text/'))) {
                        // filename の取得優先順位:
                        // 1. Content-Disposition: filename (MIME encoded-word / RFC5987 / RFC2231 連結すべて対応)
                        // 2. Content-Type: name パラメータ（古い MUA は name を使う）
                        // 3. MIME から拡張子を推測した attachment.<ext>
                        $filename = $this->parseAttachmentFilename($partDisp, $partCt);
                        $mime = trim(explode(';', $partCt)[0]);
                        if ($filename === null || $filename === '' || $filename === 'unknown') {
                            $filename = 'attachment' . self::extensionForMime($mime);
                        }
                        $decoded = $this->decodeBody($partBody, $partCte);
                        $attachments[] = [
                            'filename'  => $filename,
                            'mime_type' => $mime,
                            'size'      => strlen($decoded),
                            'binary'    => $decoded,
                        ];
                        continue;
                    }

                    $decoded = $this->decodeBody($partBody, $partCte);
                    $charset = 'UTF-8';
                    if (preg_match('/charset="?([^";\s]+)"?/i', $partCt, $cm)) {
                        $charset = $cm[1];
                    }
                    $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;

                    if (str_contains(strtolower($partCt), 'text/plain') && !$text) {
                        $text = $decoded;
                    } elseif (str_contains(strtolower($partCt), 'text/html') && !$html) {
                        $html = $decoded;
                    }
                }
            }
        } else {
            $decoded = $this->decodeBody($bodyRaw, $cte);
            $charset = 'UTF-8';
            if (preg_match('/charset="?([^";\s]+)"?/i', $contentType, $cm)) {
                $charset = $cm[1];
            }
            $decoded = @mb_convert_encoding($decoded, 'UTF-8', $charset) ?: $decoded;

            if (str_contains($ct, 'text/html')) {
                $html = $decoded;
            } else {
                $text = $decoded;
            }
        }

        return [$text, $html, $attachments];
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => base64_decode($body),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    // ── IMAP 通信 ────────────────────────────────────────

    private function connect(): bool
    {
        $host = config('services.kagoya_pop3.host');
        $port = 993;
        $user = config('services.kagoya_pop3.username');
        $pass = config('services.kagoya_pop3.password');

        $this->socket = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 15);
        if (!$this->socket) {
            Log::error("[KagoyaIMAP] 接続失敗: {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($this->socket, 30);
        fgets($this->socket); // greeting

        $resp = $this->imapCommand("LOGIN {$user} {$pass}");
        if (!$resp['ok']) {
            Log::error("[KagoyaIMAP] LOGIN 失敗");
            return false;
        }

        Log::info('[KagoyaIMAP] 接続成功');
        return true;
    }

    private function disconnect(): void
    {
        if ($this->socket) {
            $this->imapCommand('LOGOUT');
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function imapCommand(string $cmd): array
    {
        $tag = 'A' . (++$this->tagSeq);
        fwrite($this->socket, "{$tag} {$cmd}\r\n");

        $lines = [];
        while (true) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $line = rtrim($line, "\r\n");
            if (str_starts_with($line, "{$tag} ")) {
                return [
                    'ok'    => str_contains($line, 'OK'),
                    'line'  => $line,
                    'lines' => $lines,
                ];
            }
            $lines[] = $line;
        }
        return ['ok' => false, 'line' => '', 'lines' => $lines];
    }

    /**
     * @return array{body: string, internaldate: string|null}
     */
    private function fetchMessageByUid(int $uid): array
    {
        $tag = 'A' . (++$this->tagSeq);
        fwrite($this->socket, "{$tag} UID FETCH {$uid} (BODY.PEEK[] INTERNALDATE)\r\n");

        $data = '';
        $internalDate = null;
        $inBody = false;
        $remaining = 0;

        while (true) {
            $line = fgets($this->socket, 8192);
            if ($line === false) break;

            if (!$inBody) {
                // INTERNALDATE 検出: INTERNALDATE "23-Apr-2026 18:27:00 +0900"
                if (preg_match('/INTERNALDATE\s+"([^"]+)"/i', $line, $dm)) {
                    $internalDate = $dm[1];
                }
                // リテラルサイズ検出: * N FETCH (BODY[] {12345}
                if (preg_match('/\{(\d+)\}/', $line, $m)) {
                    $remaining = (int) $m[1];
                    $inBody = true;
                    // バイナリ読み取り
                    while ($remaining > 0) {
                        $chunk = fread($this->socket, min($remaining, 8192));
                        if ($chunk === false) break;
                        $data .= $chunk;
                        $remaining -= strlen($chunk);
                    }
                    continue;
                }
            }

            $trimmed = rtrim($line, "\r\n");
            if (str_starts_with($trimmed, "{$tag} ")) {
                break;
            }
            // ")" のみの行はFETCH終端
            if ($trimmed === ')' && $inBody) {
                continue;
            }
        }

        return ['body' => $data, 'internaldate' => $internalDate];
    }

    /**
     * IMAP UIDを指定して添付ファイルのバイナリを取得
     *
     * 注意: 同一メール内に同名添付が複数ある場合は最初の一致が返るだけで誤判定する。
     * part_index を持つレコードは fetchAttachmentByPartIndex を使うべき。
     * このメソッドは part_index が無い既存レコードのフォールバック用に残す。
     */
    public function fetchAttachmentByUid(int $uid, string $targetFilename): ?string
    {
        return $this->fetchAttachment($uid, function (array $attachments) use ($targetFilename): ?string {
            foreach ($attachments as $att) {
                if (!empty($att['binary']) && $att['filename'] === $targetFilename) {
                    return $att['binary'];
                }
            }
            return null;
        });
    }

    /**
     * IMAP UID + MIME part index で添付バイナリを取得（同名添付の衝突回避用）
     */
    public function fetchAttachmentByPartIndex(int $uid, int $partIndex): ?string
    {
        return $this->fetchAttachment($uid, function (array $attachments) use ($partIndex): ?string {
            if (isset($attachments[$partIndex]) && !empty($attachments[$partIndex]['binary'])) {
                return $attachments[$partIndex]['binary'];
            }
            return null;
        });
    }

    /**
     * バックフィル用: IMAP UID から添付メタ配列を返す
     * 各要素は ['filename', 'mime_type', 'size', 'binary', 'part_index'] を含む
     */
    public function fetchAttachmentsByUid(int $uid): ?array
    {
        return $this->fetchAttachment($uid, function (array $attachments): array {
            $out = [];
            foreach ($attachments as $i => $att) {
                $out[] = [
                    'filename'   => $att['filename'],
                    'mime_type'  => $att['mime_type'],
                    'size'       => $att['size'],
                    'binary'     => $att['binary'] ?? null,
                    'part_index' => $i,
                ];
            }
            return $out;
        });
    }

    /**
     * 共通: IMAP UID 取得 → parseBody → callback に添付配列を渡して結果を返す
     */
    private function fetchAttachment(int $uid, callable $picker)
    {
        if (!$this->connect()) {
            return null;
        }

        try {
            $this->imapCommand('EXAMINE INBOX');
            $result = $this->fetchMessageByUid($uid);
            if (empty($result['body'])) {
                return null;
            }

            $raw = $result['body'];
            $headerEnd = strpos($raw, "\r\n\r\n");
            if ($headerEnd === false) return null;

            $headerBlock = substr($raw, 0, $headerEnd);
            $bodyRaw     = substr($raw, $headerEnd + 4);
            $headers     = $this->parseHeaders($headerBlock);
            $contentType = $headers['content-type'] ?? 'text/plain';

            [$text, $html, $attachments] = $this->parseBody($bodyRaw, $contentType, strtolower($headers['content-transfer-encoding'] ?? '7bit'));

            return $picker($attachments);
        } finally {
            $this->disconnect();
        }
    }
}
