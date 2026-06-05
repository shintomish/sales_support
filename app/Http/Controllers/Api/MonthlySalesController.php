<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlySalesDetail;
use App\Services\MonthlySalesAggregationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 月別売上集計 API (docs/460)。
 *
 * SES台帳 (ses_contracts) を起点に契約期間ベース・月単位粗計上で集計した
 * monthly_sales_details を読む。ダッシュボードの Deal ベース見込み売上とは別系統 (並行表示)。
 */
class MonthlySalesController extends Controller
{
    public function __construct(private MonthlySalesAggregationService $aggregator) {}

    /**
     * 月別サマリ系列を返す。GET /api/v1/monthly-sales?months=6
     * (MonthlySalesDetail は BelongsToTenant で自動的に現在テナントに絞られる)
     */
    public function index(Request $request)
    {
        $tenant = Auth::user()->tenant;

        // 年度 (決算月で終わる会計年度・終了月の暦年で表記)。未指定は当年度。
        $fiscalYear = (int) $request->query('fiscal_year', $tenant->currentFiscalYear());
        $monthsDef  = $tenant->fiscalYearMonths($fiscalYear); // [['year'=>..,'month'=>..] × 12]

        // 対象 12 ヶ月の (year,month) を SUM。year*100+month キーで引く。
        $minKey = $monthsDef[0]['year'] * 100 + $monthsDef[0]['month'];
        $maxKey = $monthsDef[11]['year'] * 100 + $monthsDef[11]['month'];

        $rows = MonthlySalesDetail::query()
            ->selectRaw('year, month, '
                . 'SUM(revenue) AS revenue, SUM(cost) AS cost, SUM(profit) AS profit, '
                . 'COUNT(*) AS detail_count')
            ->whereRaw('(year * 100 + month) BETWEEN ? AND ?', [$minKey, $maxKey])
            ->groupBy('year', 'month')
            ->get()
            ->keyBy(fn($r) => $r->year * 100 + $r->month);

        $total = ['revenue' => 0, 'cost' => 0, 'profit' => 0, 'detail_count' => 0];

        $series = collect($monthsDef)->map(function ($mn) use ($rows, &$total) {
            $row = $rows->get($mn['year'] * 100 + $mn['month']);
            $rev = (int) ($row->revenue ?? 0);
            $cst = (int) ($row->cost ?? 0);
            $prf = (int) ($row->profit ?? 0);
            $cnt = (int) ($row->detail_count ?? 0);
            $total['revenue'] += $rev; $total['cost'] += $cst;
            $total['profit']  += $prf; $total['detail_count'] += $cnt;
            return [
                'year'         => $mn['year'],
                'month'        => $mn['month'],
                'label'        => "{$mn['year']}年{$mn['month']}月",
                'revenue'      => $rev,
                'cost'         => $cst,
                'profit'       => $prf,
                'detail_count' => $cnt,
            ];
        })->values();

        return response()->json([
            'fiscal_year'           => $fiscalYear,
            'period'                => $tenant->periodFor($fiscalYear),  // 期 (未設定なら null)
            'fiscal_year_end_month' => $tenant->fiscalEndMonth(),
            'monthly_sales'         => $series,
            'total'                 => $total,
        ]);
    }

    /**
     * 任意月の明細ドリルダウン。GET /api/v1/monthly-sales/{year}/{month}/details
     */
    public function details(int $year, int $month)
    {
        $details = MonthlySalesDetail::with(['sesContract:id,engineer_name,deal_id'])
            ->where('year', $year)
            ->where('month', $month)
            ->orderByDesc('revenue')
            ->get()
            ->map(fn($d) => [
                'ses_contract_id' => $d->ses_contract_id,
                'engineer_name'   => $d->sesContract?->engineer_name,
                'category'        => $d->category,
                'revenue'         => (int) $d->revenue,
                'cost'            => (int) $d->cost,
                'profit'          => (int) $d->profit,
            ]);

        return response()->json([
            'year'    => $year,
            'month'   => $month,
            'details' => $details,
        ]);
    }

    /**
     * 手動再集計。POST /api/v1/monthly-sales/recompute
     *   - { fiscal_year } 指定時: その年度の 12 ヶ月をまとめて再集計
     *   - { year, month } 指定時: 単月のみ再集計
     * 現在テナント分のみ。
     */
    public function recompute(Request $request)
    {
        $user     = Auth::user();
        $tenantId = (int) $user->tenant_id;

        // 単月指定があれば単月、なければ年度まとめて
        if ($request->filled('month')) {
            $validated = $request->validate([
                'year'  => 'required|integer|min:2000|max:2100',
                'month' => 'required|integer|min:1|max:12',
            ]);
            $result = $this->aggregator->aggregateMonth(
                (int) $validated['year'], (int) $validated['month'], $tenantId,
            );
            return response()->json($result);
        }

        $validated  = $request->validate([
            'fiscal_year' => 'required|integer|min:2000|max:2100',
        ]);
        $fiscalYear = (int) $validated['fiscal_year'];
        $months     = $user->tenant->fiscalYearMonths($fiscalYear);

        $total = ['detail_count' => 0, 'total_revenue' => 0.0, 'total_cost' => 0.0, 'total_profit' => 0.0];
        foreach ($months as $mn) {
            $r = $this->aggregator->aggregateMonth($mn['year'], $mn['month'], $tenantId);
            $total['detail_count']  += $r['detail_count'];
            $total['total_revenue'] += $r['total_revenue'];
            $total['total_cost']    += $r['total_cost'];
            $total['total_profit']  += $r['total_profit'];
        }

        return response()->json(array_merge(['fiscal_year' => $fiscalYear], $total));
    }
}
