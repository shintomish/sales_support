<?php

namespace App\Services;

use App\Models\MonthlySalesDetail;
use App\Models\SesContract;
use App\Scopes\TenantScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 月別売上集計サービス (docs/460)。
 *
 * 設計判断 (2026-06-05 確定):
 *  - 月の判定 = 契約期間ベース: contract_period_start〜end が対象月に重なる SES案件を計上。
 *  - 月またぎ  = 月単位粗計上 : 1日でも重なれば全額をその月に計上 (按分なし)。
 *  - 粒度      = 売上 (income_amount) + 仕入 (billing_plus_29) + 利益 (profit)。
 *
 * 再集計は対象 (tenant, year, month) の明細を delete → insert で作り直す (冪等)。
 * tenantId=null のときは全テナント横断 (月初スケジュールバッチ用・Auth コンテキスト無し)。
 */
class MonthlySalesAggregationService
{
    /**
     * 指定月を集計し直す。
     *
     * @param  int      $year
     * @param  int      $month     1-12
     * @param  int|null $tenantId  null=全テナント横断
     * @return array{year:int, month:int, detail_count:int, total_revenue:float, total_cost:float, total_profit:float}
     */
    public function aggregateMonth(int $year, int $month, ?int $tenantId = null): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd   = (clone $monthStart)->endOfMonth();
        $now        = Carbon::now();

        // 対象月に契約期間が重なる SES案件を抽出 (Auth に依存しないよう TenantScope を明示的に外す)。
        //   重なり条件: start <= 月末 AND (end >= 月初 OR end IS NULL=継続中)
        //   start が無い案件は月に置けないため除外。
        $query = SesContract::withoutGlobalScope(TenantScope::class)
            ->whereNotNull('contract_period_start')
            ->whereDate('contract_period_start', '<=', $monthEnd->toDateString())
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('contract_period_end')
                  ->orWhereDate('contract_period_end', '>=', $monthStart->toDateString());
            });

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $totals = ['detail_count' => 0, 'total_revenue' => 0.0, 'total_cost' => 0.0, 'total_profit' => 0.0];

        DB::transaction(function () use ($query, $year, $month, $tenantId, $now, &$totals) {
            // 既存の当月明細を削除 (冪等な作り直し)
            $deleteQuery = MonthlySalesDetail::withoutGlobalScope(TenantScope::class)
                ->where('year', $year)
                ->where('month', $month);
            if ($tenantId !== null) {
                $deleteQuery->where('tenant_id', $tenantId);
            }
            $deleteQuery->delete();

            $buffer = [];
            foreach ($query->cursor() as $c) {
                $revenue = (float) ($c->income_amount ?? 0);
                $cost    = (float) ($c->billing_plus_29 ?? 0);
                $profit  = (float) ($c->profit ?? 0);

                $buffer[] = [
                    'tenant_id'       => $c->tenant_id,
                    'ses_contract_id' => $c->id,
                    'year'            => $year,
                    'month'           => $month,
                    'category'        => $c->category,
                    'revenue'         => $revenue,
                    'cost'            => $cost,
                    'profit'          => $profit,
                    'computed_at'     => $now,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];

                $totals['detail_count']++;
                $totals['total_revenue'] += $revenue;
                $totals['total_cost']    += $cost;
                $totals['total_profit']  += $profit;

                // 大量件数でもメモリを抑えるため 500 件ごとに flush
                if (count($buffer) >= 500) {
                    MonthlySalesDetail::insert($buffer);
                    $buffer = [];
                }
            }
            if ($buffer) {
                MonthlySalesDetail::insert($buffer);
            }
        });

        return [
            'year'          => $year,
            'month'         => $month,
            'detail_count'  => $totals['detail_count'],
            'total_revenue' => round($totals['total_revenue'], 2),
            'total_cost'    => round($totals['total_cost'], 2),
            'total_profit'  => round($totals['total_profit'], 2),
        ];
    }

    /**
     * 前月を全テナント分集計し直す (月初スケジュールバッチ用)。
     *
     * @return array{year:int, month:int, detail_count:int, total_revenue:float, total_cost:float, total_profit:float}
     */
    public function aggregatePreviousMonth(): array
    {
        $prev = Carbon::now()->subMonthNoOverflow();
        return $this->aggregateMonth((int) $prev->year, (int) $prev->month, null);
    }
}
