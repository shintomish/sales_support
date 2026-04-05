<?php

namespace App\Console\Commands;

use App\Models\Email;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * メールレコードの定期クリーンアップ。
 *
 * 処理内容:
 *   1. 分類済み 30日超 → body_text / body_html を NULL化（容量削減）
 *   2. 分類済み 90日超 → レコード削除
 *   3. 未分類   14日超 → レコード削除（処理漏れとみなす）
 */
class CleanupEmails extends Command
{
    protected $signature = 'emails:cleanup {--dry-run : 実際の変更を行わずに対象件数を確認}';
    protected $description = 'メールの本文NULL化・古いレコード削除を行う';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $label  = $dryRun ? '[DRY-RUN] ' : '';

        // ── Step 1: 分類済み 30日超 → 本文NULL化 ──
        $nullifyQuery = Email::whereNotNull('classified_at')
            ->where('classified_at', '<', now()->subDays(30))
            ->where(function ($q) {
                $q->whereNotNull('body_text')->orWhereNotNull('body_html');
            });

        $nullifyCount = $nullifyQuery->count();
        $this->line("{$label}本文NULL化対象（分類済み30日超）: {$nullifyCount}件");

        if (!$dryRun && $nullifyCount > 0) {
            $nullifyQuery->update([
                'body_text' => null,
                'body_html' => null,
            ]);
        }

        // ── Step 2: 分類済み 90日超 → レコード削除 ──
        $deleteClassifiedQuery = Email::whereNotNull('classified_at')
            ->where('classified_at', '<', now()->subDays(90));

        $deleteClassifiedCount = $deleteClassifiedQuery->count();
        $this->line("{$label}レコード削除対象（分類済み90日超）: {$deleteClassifiedCount}件");

        if (!$dryRun && $deleteClassifiedCount > 0) {
            $deleteClassifiedQuery->delete();
        }

        // ── Step 3: 未分類 14日超 → レコード削除 ──
        $deleteUnclassifiedQuery = Email::whereNull('classified_at')
            ->where('received_at', '<', now()->subDays(14));

        $deleteUnclassifiedCount = $deleteUnclassifiedQuery->count();
        $this->line("{$label}レコード削除対象（未分類14日超）: {$deleteUnclassifiedCount}件");

        if (!$dryRun && $deleteUnclassifiedCount > 0) {
            $deleteUnclassifiedQuery->delete();
        }

        $this->info("{$label}完了");

        if (!$dryRun) {
            Log::info(sprintf(
                '[CleanupEmails] 本文NULL化=%d件 / 分類済み削除=%d件 / 未分類削除=%d件',
                $nullifyCount,
                $deleteClassifiedCount,
                $deleteUnclassifiedCount
            ));
        }

        return 0;
    }
}
