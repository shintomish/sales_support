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
        $months = (int) $request->query('months', 6);
        $months = max(1, min(36, $months));

        $now   = Carbon::now();
        $start = $now->copy()->subMonths($months - 1)->startOfMonth();

        // (year, month) ごとに SUM。year*100+month の範囲で絞ると index を活用できる。
        $startKey = $start->year * 100 + $start->month;

        $rows = MonthlySalesDetail::query()
            ->selectRaw('year, month, '
                . 'SUM(revenue) AS revenue, SUM(cost) AS cost, SUM(profit) AS profit, '
                . 'COUNT(*) AS detail_count')
            ->whereRaw('(year * 100 + month) >= ?', [$startKey])
            ->groupBy('year', 'month')
            ->get()
            ->keyBy(fn($r) => $r->year * 100 + $r->month);

        // 欠けている月も 0 埋めして連続系列にする
        $series = collect(range($months - 1, 0))->map(function ($i) use ($now, $rows) {
            $m   = $now->copy()->subMonths($i);
            $key = $m->year * 100 + $m->month;
            $row = $rows->get($key);
            return [
                'year'         => (int) $m->year,
                'month'        => (int) $m->month,
                'label'        => $m->format('Y年n月'),
                'revenue'      => (int) ($row->revenue ?? 0),
                'cost'         => (int) ($row->cost ?? 0),
                'profit'       => (int) ($row->profit ?? 0),
                'detail_count' => (int) ($row->detail_count ?? 0),
            ];
        })->values();

        return response()->json(['monthly_sales' => $series]);
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
     * 手動再集計。POST /api/v1/monthly-sales/recompute {year, month}
     * 現在テナントの指定月のみ再集計する。
     */
    public function recompute(Request $request)
    {
        $validated = $request->validate([
            'year'  => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $tenantId = Auth::user()->tenant_id;

        $result = $this->aggregator->aggregateMonth(
            (int) $validated['year'],
            (int) $validated['month'],
            (int) $tenantId,
        );

        return response()->json($result);
    }
}
