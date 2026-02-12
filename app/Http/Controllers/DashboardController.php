<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Activity;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // KPIカード
        $kpi = [
            'customers'      => Customer::count(),
            'deals'          => Deal::count(),
            'deals_active'   => Deal::whereNotIn('status', ['成約', '失注'])->count(),
            'won_this_month' => Deal::where('status', '成約')
                                    ->whereMonth('actual_close_date', now()->month)
                                    ->whereYear('actual_close_date', now()->year)
                                    ->count(),
            'revenue_this_month' => Deal::where('status', '成約')
                                        ->whereMonth('actual_close_date', now()->month)
                                        ->whereYear('actual_close_date', now()->year)
                                        ->sum('amount'),
            'revenue_total'  => Deal::where('status', '成約')->sum('amount'),
        ];

        // 商談パイプライン（ステータス別）
        $pipeline = Deal::select('status', DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
                        ->whereNotIn('status', ['成約', '失注'])
                        ->groupBy('status')
                        ->get();

        // 直近の活動履歴
        $recentActivities = Activity::with(['customer', 'user'])
                                    ->orderBy('activity_date', 'desc')
                                    ->limit(5)
                                    ->get();

        // 期限が近いタスク（今週）
        $upcomingTasks = Task::with(['customer'])
                             ->where('status', '!=', '完了')
                             ->where('due_date', '<=', now()->addDays(7))
                             ->orderBy('due_date', 'asc')
                             ->limit(5)
                             ->get();

        // 今月の成約商談
        $wonDeals = Deal::with('customer')
                        ->where('status', '成約')
                        ->whereMonth('actual_close_date', now()->month)
                        ->whereYear('actual_close_date', now()->year)
                        ->orderBy('amount', 'desc')
                        ->limit(5)
                        ->get();

        // 商談ステータス別件数（全体）
        $dealsByStatus = Deal::select('status', DB::raw('count(*) as count'))
                             ->groupBy('status')
                             ->pluck('count', 'status');

        return view('dashboard.index', compact(
            'kpi',
            'pipeline',
            'recentActivities',
            'upcomingTasks',
            'wonDeals',
            'dealsByStatus'
        ));
    }
}