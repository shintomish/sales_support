<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Support\Carbon;

class NotificationController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        $overdueTasks = Task::with('customer')
            ->where('status', '!=', '完了')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->orderBy('due_date')
            ->get()
            ->map(fn($t) => [
                'id'           => $t->id,
                'title'        => $t->title,
                'priority'     => $t->priority ?? '低',
                'due_date'     => $t->due_date->toDateString(),
                'customer'     => $t->customer ? ['company_name' => $t->customer->company_name] : null,
            ]);

        return response()->json([
            'overdue_tasks'       => $overdueTasks,
            'overdue_tasks_count' => $overdueTasks->count(),
        ]);
    }
}

