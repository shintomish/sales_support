<?php

namespace App\Console\Commands;

use App\Services\KagoyaMailService;
use Illuminate\Console\Command;

/**
 * 過去に届いたバウンスを Kagoya から取り直し、ハードバウンス(5.x.x)の宛先を
 * delivery_addresses で無効化する backfill コマンド。
 *
 * 取込時にバウンス本文を破棄しているため、自動抑制(取込時)は新規バウンスにしか効かない。
 * 既に届いている分を遡って処理するための手動ツール。
 *
 * 既定は dry-run（無効化対象を一覧表示するだけ）。--execute で実際に無効化する。
 *   php artisan bounce:suppress-recent --days=2            # プレビュー
 *   php artisan bounce:suppress-recent --days=2 --execute  # 実行
 */
class SuppressRecentBounces extends Command
{
    protected $signature = 'bounce:suppress-recent
                            {--days=2 : 何日前までのバウンスを対象にするか}
                            {--limit= : 走査するバウンス stub の上限(任意)}
                            {--execute : 実際に無効化する(未指定は dry-run)}';

    protected $description = '過去のバウンスを Kagoya から取り直し、ハードバウンス宛先を delivery_addresses で無効化する(既定 dry-run)';

    public function handle(KagoyaMailService $service): int
    {
        $days    = (int) $this->option('days');
        $execute = (bool) $this->option('execute');
        $limit   = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->info(($execute ? '[EXECUTE]' : '[DRY-RUN]') . " 直近 {$days} 日のバウンスを走査します...");

        $stats = $service->suppressRecentBounces($days, $execute, $limit);

        $this->line("走査 bounce stub: {$stats['scanned']} / 本文再取得: {$stats['fetched']}");
        $detected = $stats['detected'];
        $this->info('ハードバウンス宛先: ' . count($detected) . ' 件' . ($execute ? '（無効化しました）' : '（dry-run・未適用）'));
        foreach ($detected as $email) {
            $this->line(($execute ? '  停止: ' : '  対象: ') . $email);
        }

        if (!$execute && count($detected) > 0) {
            $this->newLine();
            $this->comment('実際に無効化するには --execute を付けて再実行してください。');
        }

        return self::SUCCESS;
    }
}
