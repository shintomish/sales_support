<?php

namespace App\Console\Commands;

use App\Services\KagoyaMailService;
use Illuminate\Console\Command;

/**
 * outsource@ 宛の取込済みメールを Kagoya サーバーから削除する（容量/同期負荷対策）。
 *
 * 既定は DRY-RUN（削除せず件数のみ表示）。実削除は --execute。
 * 削除対象は DB 側で定義（取込済み imap-UID・宛先・期間）するため、
 * 「未取込/別宛先/期間外」を誤って消すことは構造的に起きない。
 *
 * 例:
 *   php artisan kagoya:purge-outsource --before=2026-05-20            # DRY-RUN
 *   php artisan kagoya:purge-outsource --before=2026-05-20 --execute --force --limit=10000
 */
class PurgeOutsourceMail extends Command
{
    protected $signature = 'kagoya:purge-outsource
        {--before= : この日付より前(received_at <)を対象 (例: 2026-05-20)}
        {--older-than-days= : N日より古いを対象(--before の代わりに相対指定。スケジュール用)}
        {--address=outsource@aizen-sol.co.jp : to_address に含む宛先}
        {--limit=200000 : 1回の実行で削除する最大件数}
        {--execute : 実際に削除する(未指定は DRY-RUN)}
        {--force : 確認プロンプトをスキップ(非対話実行用)}';

    protected $description = 'outsource@ 宛の取込済みメールを Kagoya サーバーから削除する(容量/同期負荷対策)';

    public function handle(KagoyaMailService $svc): int
    {
        $before = $this->option('before');
        $olderThanDays = $this->option('older-than-days');
        if (!$before && $olderThanDays !== null && $olderThanDays !== '') {
            $before = now()->subDays((int) $olderThanDays)->format('Y-m-d');
        }
        if (!$before) {
            $this->error('--before か --older-than-days のいずれかが必須です (例: --before=2026-05-20 / --older-than-days=14)');
            return self::FAILURE;
        }
        $address = (string) $this->option('address');
        $limit = (int) $this->option('limit');
        $execute = (bool) $this->option('execute');

        $this->info("対象: to_address に「{$address}」を含み received_at < {$before} の取込済み(imap-)メール");
        $this->info($execute ? "モード: 実削除 (limit={$limit})" : 'モード: DRY-RUN (削除しません)');

        if ($execute && !$this->option('force')) {
            if (!$this->confirm("Kagoya サーバーから最大 {$limit} 件を完全削除します。続行しますか?", false)) {
                $this->warn('中止しました');
                return self::SUCCESS;
            }
        }

        try {
            $stats = $svc->purgeOutsourceMail($before, $address, $execute, $limit);
        } catch (\Throwable $e) {
            $this->error('失敗: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->table(
            ['key', 'value'],
            collect($stats)->map(fn ($v, $k) => [$k, is_null($v) ? '-' : (is_bool($v) ? ($v ? 'true' : 'false') : $v)])->values()->all()
        );

        if (!$execute) {
            $this->info("DRY-RUN: 削除可能 {$stats['deletable']} 件 (DB対象 {$stats['db_target']} / サーバー古 {$stats['server_old']})。実削除は --execute --force を付与。");
        } else {
            $this->info("削除完了: flagged={$stats['flagged']} expunged={$stats['expunged']} (EXISTS {$stats['exists_before']} → {$stats['exists_after']})。残りがあれば再実行で続行。");
        }
        return self::SUCCESS;
    }
}
