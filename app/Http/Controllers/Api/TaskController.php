<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Http\Resources\TaskResource;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::today();
        $userFilter = $this->resolveUserFilter($request);

        $tasks = Task::with(['customer', 'deal', 'user'])
            ->when($userFilter,            fn($q, $id) => $q->where('user_id', $id))
            ->when($request->search, fn($q, $s) =>
                $q->where('title', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->status,   fn($q, $s) => $q->where('status', $s))
            ->when($request->priority, fn($q, $p) => $q->where('priority', $p))
            // 期限フィルター
            ->when($request->due_filter, function($q, $filter) use ($today) {
                return match($filter) {
                    'today'   => $q->whereDate('due_date', $today),
                    'overdue' => $q->whereDate('due_date', '<', $today)->where('status', '!=', '完了'),
                    'week'    => $q->whereBetween('due_date', [$today, $today->copy()->endOfWeek()]),
                    default   => $q,
                };
            })
            ->when($request->get('sort_by'), fn($q) => $q->orderBy(
                ...$this->resolveSort($request, [
                    'title'    => 'title',
                    'due_date' => 'due_date',
                    'status'   => 'status',
                    'priority' => 'priority',
                ], 'due_date', 'asc')
            ), fn($q) => $q
                ->orderByRaw("CASE status WHEN '未着手' THEN 1 WHEN '進行中' THEN 2 WHEN '完了' THEN 3 ELSE 4 END")
                ->orderByRaw("CASE priority WHEN '高' THEN 1 WHEN '中' THEN 2 WHEN '低' THEN 3 ELSE 4 END")
            )
            ->paginate(20);
        return TaskResource::collection($tasks);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'priority'    => 'required|in:高,中,低',
            'status'      => 'required|in:未着手,進行中,完了',
            'due_date'    => 'nullable|date',
            'customer_id' => 'nullable|exists:customers,id',
            'deal_id'     => 'nullable|exists:deals,id',
            'description' => 'nullable|string|max:2000',
        ], $this->messages());

        $validated['user_id'] = $request->user()->id;
        $task = Task::create($validated);
        return new TaskResource($task);
    }

    public function show(Task $task)
    {
        $task->load(['customer', 'deal', 'user']);
        return new TaskResource($task);
    }

    public function update(Request $request, Task $task)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'priority'    => 'required|in:高,中,低',
            'status'      => 'required|in:未着手,進行中,完了',
            'due_date'    => 'nullable|date',
            'customer_id' => 'nullable|exists:customers,id',
            'deal_id'     => 'nullable|exists:deals,id',
            'description' => 'nullable|string|max:2000',
        ], $this->messages());

        $task->update($validated);
        return new TaskResource($task);
    }

    public function updateStatus(Request $request, Task $task)
    {
        $request->validate(
            ['status' => 'required|in:未着手,進行中,完了'],
            ['status.required' => 'ステータスは必須です', 'status.in' => 'ステータスの値が正しくありません']
        );
        $task->update(['status' => $request->status]);
        return new TaskResource($task);
    }

    public function destroy(Task $task)
    {
        $task->delete();
        return response()->json(null, 204);
    }

    private function messages(): array
    {
        return [
            'title.required'      => 'タスク名は必須です',
            'title.max'           => 'タスク名は255文字以内で入力してください',
            'priority.required'   => '優先度を選択してください',
            'priority.in'         => '優先度の値が正しくありません',
            'status.required'     => 'ステータスを選択してください',
            'status.in'           => 'ステータスの値が正しくありません',
            'due_date.date'       => '期限日の形式が正しくありません',
            'customer_id.exists'  => '選択された顧客が存在しません',
            'deal_id.exists'      => '選択された商談が存在しません',
            'description.max'     => '説明は2000文字以内で入力してください',
        ];
    }
}
