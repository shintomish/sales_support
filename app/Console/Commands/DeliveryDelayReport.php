<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kagoya 配送遅延レポート (docs/460 / project_kagoya_gmail_delivery)。
 *
 * 遅延 = arrived_at(IMAP INTERNALDATE=Kagoya 着信) − received_at(Date=送信時刻)。
 * 全体サマリ / 時間帯別(JST) / 送信元ドメイン別 を出力する。
 *
 * 用途: 滞留の傾向把握、Kagoya への改善要請エビデンス。
 * 注意: 2026-06-05 より前の行は arrived_at=created_at(取込時刻) で backfill されており
 *       取込ラグを含む。純粋な配送遅延は同日以降の行が正確。
 *
 * 例: php artisan emails:delivery-delay-report --days=7 --tenant=1
 */
class DeliveryDelayReport extends Command
{
    protected $signature = 'emails:delivery-delay-report
        {--days=7 : 集計対象期間(日)}
        {--tenant= : テナントID (未指定=全テナント)}
        {--top=15 : 送信元ドメインの表示件数}
        {--min-count=20 : 送信元別の最小件数(ノイズ除外)}';

    protected $description = 'Kagoya 配送遅延 (到着-送信) を全体/時間帯別/送信元別に集計';

    public function handle(): int
    {
        $days     = max(1, (int) $this->option('days'));
        $tenant   = $this->option('tenant');
        $top      = max(1, (int) $this->option('top'));
        $minCount = max(1, (int) $this->option('min-count'));

        // 共通 WHERE: 期間・arrived/received 揃い・遅延が非負(時計逆転やノイズ除外)
        $where  = "created_at > now() - (? || ' days')::interval
                   AND arrived_at IS NOT NULL AND received_at IS NOT NULL
                   AND arrived_at >= received_at";
        $params = [$days];
        if ($tenant !== null && $tenant !== '') {
            $where .= ' AND tenant_id = ?';
            $params[] = (int) $tenant;
        }

        $delayMin = "extract(epoch from (arrived_at - received_at))/60";

        $this->info("=== Kagoya 配送遅延レポート (直近 {$days} 日"
            . ($tenant ? " / tenant={$tenant}" : ' / 全テナント') . ") ===");

        // 1) 全体サマリ
        $s = DB::selectOne("
            SELECT count(*) n,
              round(avg($delayMin))::int avg_min,
              round((percentile_cont(0.5) within group (order by $delayMin))::numeric)::int p50_min,
              round((percentile_cont(0.9) within group (order by $delayMin))::numeric)::int p90_min,
              round(max($delayMin))::int max_min
            FROM public.emails WHERE $where", $params);

        if (!$s || (int) $s->n === 0) {
            $this->warn('対象データがありません。');
            return self::SUCCESS;
        }

        $fmt = fn($m) => $m === null ? '-' : sprintf('%d分 (%.1fh)', $m, $m / 60);
        $this->line("件数: {$s->n}");
        $this->line("平均: {$fmt($s->avg_min)} / 中央値: {$fmt($s->p50_min)} / p90: {$fmt($s->p90_min)} / 最大: {$fmt($s->max_min)}");

        // 2) 時間帯別 (送信時刻の JST hour 基準)
        $byHour = DB::select("
            SELECT extract(hour from (received_at AT TIME ZONE 'Asia/Tokyo'))::int hh,
              count(*) n, round(avg($delayMin))::int avg_min
            FROM public.emails WHERE $where
            GROUP BY 1 ORDER BY 1", $params);

        $this->newLine();
        $this->info('— 時間帯別 (送信時刻 JST) —');
        $this->table(['時(JST)', '件数', '平均遅延'],
            array_map(fn($r) => [sprintf('%02d時', $r->hh), $r->n, $fmt($r->avg_min)], $byHour));

        // 3) 送信元ドメイン別 (平均遅延の大きい順)
        $domainExpr = "lower(split_part(from_address, '@', 2))";
        $byDomain = DB::select("
            SELECT $domainExpr domain, count(*) n,
              round(avg($delayMin))::int avg_min, round(max($delayMin))::int max_min
            FROM public.emails WHERE $where AND from_address LIKE '%@%'
            GROUP BY 1 HAVING count(*) >= ?
            ORDER BY avg(($delayMin)) DESC LIMIT ?",
            array_merge($params, [$minCount, $top]));

        $this->newLine();
        $this->info("— 送信元ドメイン別 遅延ワースト{$top} (件数{$minCount}以上) —");
        $this->table(['ドメイン', '件数', '平均遅延', '最大遅延'],
            array_map(fn($r) => [$r->domain, $r->n, $fmt($r->avg_min), $fmt($r->max_min)], $byDomain));

        return self::SUCCESS;
    }
}
