<?php

namespace App\Console\Commands;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Services\KagoyaMailService;
use App\Services\SupabaseStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * IMAP 取込済みメールの「同名添付 storage_path 衝突」破損データを復元する backfill。
 *
 * 経緯:
 *   2026-05-26 発見、5-28 `e0add49` で新規取込の衝突回避は完了 (Phase 1)。
 *   既存破損レコードは元ファイルが上書きで失われているため、IMAP から MIME part
 *   index 指定で再取得し直して storage_path を新フォーマット ({id}_{base}.{ext})
 *   で上書き保存する。Phase 2 仕上げ。
 *
 * 対象抽出条件 (OR):
 *   - 同一 email 内で storage_path が重複している添付がある (真の衝突)
 *   - 汎用ファイル名 (unknown.bin / attachment.* など) を含む (衝突リスク)
 *
 * 単に part_index が null なだけのメール (= Phase 1 前の単独添付) は衝突リスクが無いので
 * 対象から除外する。新規 DL 時に filename フォールバックで動作するため。
 *
 * 安全策:
 *   - IMAP 添付数と DB 添付数が一致しない場合は WARNING でスキップ (誤割当防止)
 *   - 1 メール処理ごとに 200ms sleep (Kagoya IMAP の rate-limit 配慮)
 *   - --dry-run / --limit / --batch-size オプションで段階実行可
 */
class BackfillImapAttachments extends Command
{
    protected $signature = 'attachments:backfill-imap-broken
        {--dry-run : 実行せず集計のみ}
        {--limit=0 : 処理対象メール数上限 (0=無制限)}
        {--batch-size=50 : DB から一度に取得するメール数}
        {--sleep-ms=200 : 1メールごとのスリープ (ms)}';

    protected $description = 'IMAP取込済みの同名添付衝突破損を part_index ベースで復元';

    public function handle(KagoyaMailService $kagoya, SupabaseStorageService $storage): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $limit     = (int) $this->option('limit');
        $batchSize = max(1, (int) $this->option('batch-size'));
        $sleepUs   = max(0, (int) $this->option('sleep-ms')) * 1000;

        $candidateIds = $this->collectCandidateEmailIds();
        if ($limit > 0) {
            $candidateIds = array_slice($candidateIds, 0, $limit);
        }

        $this->info('対象メール: ' . count($candidateIds) . '件' . ($dryRun ? ' (DRY-RUN)' : ''));
        if (empty($candidateIds)) {
            return 0;
        }

        // dry-run は IMAP fetch を行わず、対象一覧の出力のみ。
        // (IMAP creds 未設定環境でも抽出ロジックを確認できるようにする)
        if ($dryRun) {
            $sampleEmails = Email::with('attachments')
                ->whereIn('id', $candidateIds)
                ->orderBy('id')
                ->get();

            foreach ($sampleEmails as $email) {
                $atts = $email->attachments;
                $files = $atts->pluck('filename')->take(3)->implode(', ');
                if ($atts->count() > 3) $files .= ' ...';
                $this->line(sprintf(
                    '  [DRY] email_id=%d uid=%s att_count=%d files=%s',
                    $email->id,
                    str_replace('imap-', '', $email->gmail_message_id),
                    $atts->count(),
                    $files
                ));
            }
            $this->newLine();
            $this->info('=== 結果 (DRY-RUN) ===');
            $this->line(sprintf('  %-25s %d', '候補メール', count($candidateIds)));
            $this->line(sprintf('  %-25s %d', '候補添付合計', \App\Models\EmailAttachment::whereIn('email_id', $candidateIds)->count()));
            return 0;
        }

        $stats = [
            'processed' => 0,
            'restored'  => 0,
            'skipped_count_mismatch' => 0,
            'skipped_no_imap'        => 0,
            'errors'                 => 0,
        ];

        foreach (array_chunk($candidateIds, $batchSize) as $chunk) {
            $emails = Email::with('attachments')
                ->whereIn('id', $chunk)
                ->orderBy('id')
                ->get();

            foreach ($emails as $email) {
                $stats['processed']++;
                try {
                    $imapUid = (int) str_replace('imap-', '', $email->gmail_message_id);
                    if ($imapUid <= 0) {
                        $stats['skipped_no_imap']++;
                        continue;
                    }

                    $imapAtts = $kagoya->fetchAttachmentsByUid($imapUid);
                    if ($imapAtts === null) {
                        $stats['skipped_no_imap']++;
                        $this->warn("  uid={$imapUid} email_id={$email->id}: IMAP取得失敗");
                        continue;
                    }

                    $dbAtts  = $email->attachments->sortBy('id')->values();
                    if (count($imapAtts) !== $dbAtts->count()) {
                        $stats['skipped_count_mismatch']++;
                        $this->warn("  uid={$imapUid} email_id={$email->id}: 添付数不一致 (DB={$dbAtts->count()}, IMAP=" . count($imapAtts) . ")");
                        continue;
                    }

                    $this->restoreEmail($email, $dbAtts, $imapAtts, $storage);
                    $stats['restored']++;

                    if ($sleepUs > 0) {
                        usleep($sleepUs);
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::warning('[backfill-imap] email_id=' . $email->id . ' エラー: ' . $e->getMessage());
                    $this->warn("  email_id={$email->id}: 例外 " . $e->getMessage());
                }
            }
        }

        $this->newLine();
        $this->info('=== 結果 ===');
        foreach ($stats as $k => $v) {
            $this->line(sprintf('  %-25s %d', $k, $v));
        }

        return 0;
    }

    /**
     * 衝突が疑われるメール ID を抽出。
     */
    private function collectCandidateEmailIds(): array
    {
        // 条件A: 同一 email 内で storage_path が重複
        $aIds = DB::table('email_attachments')
            ->select('email_id')
            ->whereNotNull('storage_path')
            ->groupBy('email_id', 'storage_path')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('email_id')
            ->all();

        // 条件B: 汎用ファイル名を持つ
        $bIds = DB::table('email_attachments')
            ->select('email_id')
            ->where(function ($q) {
                $q->where('filename', 'like', 'unknown.%')
                  ->orWhere('filename', 'unknown')
                  ->orWhere('filename', 'like', 'attachment.%')
                  ->orWhere('filename', 'attachment');
            })
            ->distinct()
            ->pluck('email_id')
            ->all();

        $candidates = array_unique(array_merge($aIds, $bIds));
        if (empty($candidates)) {
            return [];
        }

        // IMAP メールに限定
        return Email::whereIn('id', $candidates)
            ->where('gmail_message_id', 'like', 'imap-%')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * 1メール分の復元。DB 添付と IMAP 添付を順序で突き合わせ、storage を上書き保存。
     */
    private function restoreEmail(
        Email $email,
        \Illuminate\Support\Collection $dbAtts,
        array $imapAtts,
        SupabaseStorageService $storage
    ): void {
        foreach ($dbAtts as $i => $att) {
            $imap = $imapAtts[$i];
            $partIndex = (int) $imap['part_index'];

            $update = ['part_index' => $partIndex];

            if (!empty($imap['binary'])) {
                $ext  = strtolower(pathinfo($att->filename, PATHINFO_EXTENSION)) ?: 'bin';
                $base = preg_replace('/[^\w\-\.]/u', '_', pathinfo($att->filename, PATHINFO_FILENAME));
                $base = preg_replace('/[^\x00-\x7F]/u', '', $base) ?: substr(md5($att->filename), 0, 8);
                $path = "attachments/{$email->tenant_id}/{$email->id}/{$att->id}_{$base}.{$ext}";

                try {
                    $url = $storage->uploadBinary($imap['binary'], $path, $att->mime_type ?? 'application/octet-stream');
                    if ($url) {
                        $update['storage_path'] = $url;
                    }
                } catch (\Throwable $e) {
                    Log::warning("[backfill-imap] uploadBinary 失敗 att_id={$att->id}: " . $e->getMessage());
                }
            }

            $att->update($update);
        }
    }
}
