<?php
// app/Http/Controllers/Api/DashboardController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\Activity;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $now = Carbon::now();

        // KPI
        $kpi = [
            'customers'          => Customer::count(),
            'deals_active'       => Deal::whereNotIn('status', ['成約', '失注'])->count(),
            'won_this_month'     => Deal::where('status', '成約')
                                        ->whereMonth('updated_at', $now->month)
                                        ->whereYear('updated_at', $now->year)
                                        ->count(),
            'revenue_this_month' => Deal::where('status', '成約')
                                        ->whereMonth('updated_at', $now->month)
                                        ->whereYear('updated_at', $now->year)
                                        ->sum('amount'),
            'deals'              => Deal::count(),
        ];

        // 商談パイプライン
        $pipeline = Deal::selectRaw('status, count(*) as count, sum(amount) as total')
            ->groupBy('status')
            ->get()
            ->map(fn($d) => [
                'status' => $d->status,
                'count'  => $d->count,
                'total'  => (int) $d->total,
            ]);

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
            'upcoming_tasks'    => $upcomingTasks,
            'recent_activities' => $recentActivities,
            'won_deals'         => $wonDeals,
        ]);
    }
}
