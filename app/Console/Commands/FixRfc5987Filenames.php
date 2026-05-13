<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use Illuminate\Console\Command;

class FixRfc5987Filenames extends Command
{
    protected $signature = 'attachments:fix-rfc5987 {--dry-run : 実行せず確認のみ}';
    protected $description = 'RFC 5987 形式 (utf-8\'\'...) の生エンコードファイル名を percent-decode して正規化';

    public function handle(): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $updated = 0;
        $failed  = 0;
        $skipped = 0;

        // charset''percent-encoded で始まる filename を対象に
        // (例) utf-8''Y.S%5F%E3%82%B9... / UTF-8''... / iso-2022-jp''...
        EmailAttachment::query()
            ->where('filename', '~*', "^[A-Za-z0-9-]+''")
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$updated, &$failed, &$skipped, $dryRun) {
                foreach ($chunk as $att) {
                    if (! preg_match("/^([A-Za-z0-9\-]+)''(.+)$/", $att->filename, $m)) {
                        $skipped++;
                        continue;
                    }
                    $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
                    $value   = urldecode($m[2]);
                    $decoded = @mb_convert_encoding($value, 'UTF-8', $charset);

                    if ($decoded === false || $decoded === '' || $decoded === $att->filename) {
                        $failed++;
                        $this->warn("[{$att->id}] デコード失敗: {$att->filename}");
                        continue;
                    }

                    $this->line("[{$att->id}] {$att->filename}");
                    $this->line("       → {$decoded}");

                    if (! $dryRun) {
                        $att->update(['filename' => $decoded]);
                    }
                    $updated++;
                }
            });

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . "更新: {$updated} / 失敗: {$failed} / スキップ: {$skipped}");
        return self::SUCCESS;
    }
}
