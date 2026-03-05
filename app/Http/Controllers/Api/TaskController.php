<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Http\Resources\TaskResource;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $tasks = Task::with(['customer', 'deal', 'user'])
            ->when($request->search, fn($q, $s) =>
                $q->where('title', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->status,   fn($q, $s) => $q->where('status', $s))
            ->when($request->priority, fn($q, $p) => $q->where('priority', $p))
            ->orderByRaw("FIELD(status, '未着手', '進行中', '完了')")
            ->orderByRaw("FIELD(priority, '高', '中', '低')")
            ->paginate(20);
        return TaskResource::collection($tasks);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'priority'    => 'required|in:高,中,低',           // ★ 追加
            'status'      => 'required|in:未着手,進行中,完了',
            'due_date'    => 'nullable|date',
            'customer_id' => 'nullable|exists:customers,id',   // ★ 追加
            'deal_id'     => 'nullable|exists:deals,id',       // ★ 追加
            'description' => 'nullable|string',
        ]);
        $validated['user_id'] = $request->user()->id;
        $task = Task::create($validated);
        return new TaskResource($task);
    }

    public function show(Task $task)
    {
        $task->load(['customer', 'deal', 'user']); // ★ eager load追加
        return new TaskResource($task);
    }

    public function update(Request $request, Task $task)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'priority'    => 'required|in:高,中,低',           // ★ 追加
            'status'      => 'required|in:未着手,進行中,完了',
            'due_date'    => 'nullable|date',
            'customer_id' => 'nullable|exists:customers,id',   // ★ 追加
            'deal_id'     => 'nullable|exists:deals,id',       // ★ 追加
            'description' => 'nullable|string',
        ]);
        $task->update($validated);
        return new TaskResource($task);
    }

    // ★ ステータス更新（詳細画面のボタン用）
    public function updateStatus(Request $request, Task $task)
    {
        $request->validate(['status' => 'required|in:未着手,進行中,完了']);
        $task->update(['status' => $request->status]);
        return new TaskResource($task);
    }
    
    public function destroy(Task $task)
    {
        $task->delete();
        return response()->json(null, 204);
    }
}
