<?php

namespace App\Services;

use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\EmailAttachment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class KagoyaMailService
{
    private $socket;
    private int $tagSeq = 0;

    /**
     * KAGOYA IMAP から直接メールを取得し emails テーブルに保存する
     */
    public function syncEmails(int $maxFetch = 100): int
    {
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

            // 最新 $maxFetch 件の UID を取得
            $start = max(1, $exists - $maxFetch + 1);
            $fetchResp = $this->imapCommand("FETCH {$start}:{$exists} (UID)");

            $uids = [];
            foreach ($fetchResp['lines'] as $line) {
                if (preg_match('/UID\s+(\d+)/', $line, $m)) {
                    $uids[] = (int) $m[1];
                }
            }

            // 既存 UID を一括チェック
            $existingUids = Email::where('tenant_id', $tenantId)
                ->whereIn('gmail_message_id', array_map(fn($u) => "imap-{$u}", $uids))
                ->pluck('gmail_message_id')
                ->map(fn($id) => str_replace('imap-', '', $id))
                ->toArray();

            $newUids = array_filter($uids, fn($u) => !in_array((string) $u, $existingUids));

            if (empty($newUids)) {
                Log::info("[KagoyaIMAP] 新着なし");
                return 0;
            }

            Log::info("[KagoyaIMAP] 新着: " . count($newUids) . "件");

            $stored = 0;
            // UID FETCH で本文取得（一件ずつ）
            foreach ($newUids as $uid) {
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

            Log::info("[KagoyaIMAP] 同期完了", ['stored' => $stored]);
            return $stored;
        } finally {
            $this->disconnect();
        }
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

        [$fromName, $fromAddress] = $this->parseFrom($from);

        // INTERNALDATE（サーバー受信時刻）を優先、なければDateヘッダー
        $receivedAt = $internalDate
            ? Carbon::parse($internalDate)->utc()
            : ($headers['date'] ?? null
                ? Carbon::parse($headers['date'])->utc()
                : Carbon::now()->utc());

        // バウンスメール（不達通知）/ 上流スパム判定済みメールは category='bounce' の
        // 最小 stub だけ保存し、本文/添付処理はスキップ。
        // 旧実装は何も保存しなかったため、毎回 IMAP から同じ UID が「新着」として返り
        // CPU と Kagoya API を浪費していた (dedup 用 anchor の役割を兼ねる)。
        $lcFrom = strtolower($fromAddress);
        $lcSubject = strtolower($subject);
        if (str_starts_with(trim($lcSubject), '[spam]') ||
            str_contains($lcFrom, 'mailer-daemon') ||
            str_contains($lcFrom, 'postmaster') ||
            str_contains($lcSubject, 'undelivered') ||
            str_contains($lcSubject, 'returned mail') ||
            str_contains($lcSubject, 'delivery status') ||
            str_contains($lcSubject, 'undeliverable') ||
            str_contains($lcSubject, 'failure notice') ||
            str_contains($lcSubject, 'mail delivery failed')) {
            Email::create([
                'tenant_id'        => $tenantId,
                'gmail_message_id' => $uid,
                'thread_id'        => null,
                'subject'          => mb_substr($subject, 0, 255),
                'from_address'     => $fromAddress,
                'from_name'        => $fromName,
                'to_address'       => mb_substr($to, 0, 500),
                'body_text'        => null,
                'body_html'        => null,
                'received_at'      => $receivedAt,
                'is_read'          => true,    // 未読カウントを汚さない
                'category'         => 'bounce', // classifyPending(whereNull) に拾わせない
                'classified_at'    => $receivedAt,
            ]);
            return false;
        }

        $contentType = $headers['content-type'] ?? 'text/plain';
        $cte = strtolower($headers['content-transfer-encoding'] ?? '7bit');
        [$bodyText, $bodyHtml, $attachments] = $this->parseBody($bodyRaw, $contentType, $cte);

        $email = Email::create([
            'tenant_id'        => $tenantId,
            'gmail_message_id' => $uid,
            'thread_id'        => null,
            'subject'          => mb_substr($subject, 0, 255),
            'from_address'     => $fromAddress,
            'from_name'        => $fromName,
            'to_address'       => mb_substr($to, 0, 500),
            'body_text'        => $bodyText,
            'body_html'        => $bodyHtml,
            'received_at'      => $receivedAt,
            'is_read'          => false,
        ]);

        foreach ($attachments as $att) {
            $storagePath = null;
            if (!empty($att['binary'])) {
                try {
                    $ext  = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION)) ?: 'bin';
                    $base = preg_replace('/[^\w\-\.]/u', '_', pathinfo($att['filename'], PATHINFO_FILENAME));
                    $base = preg_replace('/[^\x00-\x7F]/u', '', $base) ?: substr(md5($att['filename']), 0, 8);
                    $path = "attachments/{$tenantId}/{$email->id}/{$base}.{$ext}";
                    $storage = app(\App\Services\SupabaseStorageService::class);
                    $storagePath = $storage->uploadBinary($att['binary'], $path, $att['mime_type']);
                } catch (\Throwable $e) {
                    Log::warning("[KagoyaIMAP] 添付Storage保存失敗: {$att['filename']}: " . $e->getMessage());
                }
            }
            EmailAttachment::create([
                'email_id'            => $email->id,
                'filename'            => $att['filename'],
                'mime_type'           => $att['mime_type'],
                'size'                => $att['size'],
                'gmail_attachment_id' => null,
                'storage_path'        => $storagePath,
            ]);
        }

        // 返信紐づけ
        $inReplyTo = trim($headers['in-reply-to'] ?? '');
        $history = null;

        // ① In-Reply-To → ses_message_id 完全一致
        if ($inReplyTo) {
            $history = DeliverySendHistory::where('ses_message_id', $inReplyTo)
                ->whereNull('reply_email_id')
                ->first();

            // ② < > 除去してのフォールバック
            if (!$history) {
                $clean = trim($inReplyTo, '<>');
                $history = DeliverySendHistory::where('ses_message_id', 'like', "%{$clean}%")
                    ->whereNull('reply_email_id')
                    ->first();
            }
        }

        // ③ 差出人メール + 件名（Re:除去）で最新の送信履歴を探す
        if (!$history && $fromAddress) {
            $originalSubject = trim(preg_replace('/^(Re:\s*|RE:\s*|Fwd:\s*|FW:\s*)*/iu', '', $subject));
            if ($originalSubject) {
                $history = DeliverySendHistory::where('email', $fromAddress)
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

    // ── パース系 ────────────────────────────────────────

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
     */
    public function fetchAttachmentByUid(int $uid, string $targetFilename): ?string
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

            foreach ($attachments as $att) {
                if (!empty($att['binary']) && $att['filename'] === $targetFilename) {
                    return $att['binary'];
                }
            }

            return null;
        } finally {
            $this->disconnect();
        }
    }
}
