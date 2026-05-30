<?php
// app/Http/Controllers/Api/DashboardController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class DashboardController extends Controller
{
    #[OA\Get(
        path: '/api/v1/dashboard',
        summary: 'ダッシュボード情報取得',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        responses: [
            new OA\Response(response: 200, description: 'KPI・パイプライン・月別売上・タスク・活動履歴・成約商談'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function index()
    {
        $now      = Carbon::now();
        $tenantId = Auth::user()?->tenant_id;

        // KPI: 旧実装は Deal テーブルに 4 本のクエリ (count/count/count/sum) を発行していたが、
        // SUM(CASE WHEN ...) で 1 本に集約。whereMonth/whereYear (EXTRACT) は index 未使用だったが
        // updated_at >= startOfMonth の範囲条件にして deals(updated_at) を活用可能にした。
        // 結果全体を tenant 単位で 30 秒キャッシュ (docs/730 §Medium #11)。
        $cacheKey = "dashboard:{$tenantId}";
        $kpi = Cache::remember($cacheKey . ':kpi', 30, function () use ($now) {
            $startOfMonth = $now->copy()->startOfMonth();
            $row = Deal::query()
                ->selectRaw(
                    "COUNT(*) AS total_deals,
                     SUM(CASE WHEN status NOT IN ('成約', '失注') THEN 1 ELSE 0 END) AS active_deals,
                     SUM(CASE WHEN status = '成約' AND updated_at >= ? THEN 1 ELSE 0 END) AS won_this_month,
                     SUM(CASE WHEN status = '成約' AND updated_at >= ? THEN amount ELSE 0 END) AS revenue_this_month",
                    [$startOfMonth, $startOfMonth]
                )
                ->first();
            return [
                'customers'          => Customer::count(),
                'deals_active'       => (int) ($row->active_deals ?? 0),
                'won_this_month'     => (int) ($row->won_this_month ?? 0),
                'revenue_this_month' => (int) ($row->revenue_this_month ?? 0),
                'deals'              => (int) ($row->total_deals ?? 0),
            ];
        });

        // 商談パイプライン
        $pipeline = Deal::selectRaw('status, count(*) as count, sum(amount) as total')
            ->groupBy('status')
            ->get()
            ->map(fn($d) => [
                'status' => $d->status,
                'count'  => $d->count,
                'total'  => (int) $d->total,
            ]);

        // 月別売上（過去6ヶ月） — N+1 解消版 (Sentry PHP-LARAVEL-C 対応)
        // 旧: 月ごとに sum() を 6 回発行し whereMonth/whereYear で EXTRACT() を使うためインデックス未活用
        // 新: DATE_TRUNC('month', ...) で 1 クエリ集計 + updated_at の範囲検索でインデックス活用
        $startOfWindow = $now->copy()->subMonths(5)->startOfMonth();
        $totalsByMonth = Deal::where('status', '成約')
            ->where('updated_at', '>=', $startOfWindow)
            ->selectRaw("DATE_TRUNC('month', updated_at) AS month_start, SUM(amount) AS revenue")
            ->groupBy('month_start')
            ->get()
            ->mapWithKeys(fn($row) => [Carbon::parse($row->month_start)->format('Y-m') => (int) $row->revenue]);

        $monthlyRevenue = collect(range(5, 0))->map(function ($i) use ($now, $totalsByMonth) {
            $month = $now->copy()->subMonths($i);
            return [
                'month'   => $month->format('n月'),
                'revenue' => $totalsByMonth[$month->format('Y-m')] ?? 0,
            ];
        })->values();

        // 期限が近いタスク（7日以内・未完了）
        $upcomingTasks = Task::with('customer')
            ->where('status', '!=', '完了')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $now->copy()->addDays(7))
            ->orderBy('due_date')
            ->limit(5)
            ->get()
            ->map(fn($t) => [
                'id'       => $t->id,
                'title'    => $t->title,
                'priority' => $t->priority ?? '低',
                'due_date' => $t->due_date?->toDateString(),
                'customer' => $t->customer
                    ? ['company_name' => $t->customer->company_name]
                    : null,
            ]);

        // 直近の活動履歴
        $recentActivities = Activity::with('customer')
            ->orderBy('activity_date', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($a) => [
                'id'            => $a->id,
                'subject'       => $a->subject,
                'type'          => $a->type,
                'activity_date' => $a->activity_date->toDateString(),
                'customer'      => $a->customer
                    ? ['company_name' => $a->customer->company_name]
                    : null,
            ]);

        // 今月の成約商談
        $wonDeals = Deal::with('customer')
            ->where('status', '成約')
            ->whereMonth('updated_at', $now->month)
            ->whereYear('updated_at', $now->year)
            ->orderBy('amount', 'desc')
            ->limit(5)
            ->get()
            ->map(fn($d) => [
                'id'       => $d->id,
                'title'    => $d->title,
                'amount'   => (int) $d->amount,
                'customer' => $d->customer
                    ? ['company_name' => $d->customer->company_name]
                    : null,
            ]);

        return response()->json([
            'kpi'               => $kpi,
            'pipeline'          => $pipeline,
            'monthly_revenue'   => $monthlyRevenue,
            'upcoming_tasks'    => $upcomingTasks,
            'recent_activities' => $recentActivities,
            'won_deals'         => $wonDeals,
        ]);
    }
}
