<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Http\Resources\TaskResource;
use Illuminate\Http\Request;
use Carbon\Carbon;
use OpenApi\Attributes as OA;

class TaskController extends Controller
{
    #[OA\Get(
        path: '/api/v1/tasks',
        summary: 'タスク一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'タスク名・会社名で検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'ステータス', schema: new OA\Schema(type: 'string', enum: ['未着手', '進行中', '完了'])),
            new OA\Parameter(name: 'priority', in: 'query', required: false, description: '優先度', schema: new OA\Schema(type: 'string', enum: ['高', '中', '低'])),
            new OA\Parameter(name: 'due_filter', in: 'query', required: false, description: '期限フィルター', schema: new OA\Schema(type: 'string', enum: ['today', 'overdue', 'week'])),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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
            ->when($request->get('sort_by') === 'assignee', fn($q) =>
                $q->leftJoin('users as sort_users', 'tasks.user_id', '=', 'sort_users.id')->select('tasks.*')
            )
            ->when($request->get('sort_by'), fn($q) => $q->orderBy(
                ...$this->resolveSort($request, [
                    'title'    => 'tasks.title',
                    'due_date' => 'tasks.due_date',
                    'status'   => 'tasks.status',
                    'priority' => 'tasks.priority',
                    'assignee' => 'sort_users.name',
                ], 'tasks.due_date', 'asc')
            ), fn($q) => $q
                ->orderByRaw("CASE status WHEN '未着手' THEN 1 WHEN '進行中' THEN 2 WHEN '完了' THEN 3 ELSE 4 END")
                ->orderByRaw("CASE priority WHEN '高' THEN 1 WHEN '中' THEN 2 WHEN '低' THEN 3 ELSE 4 END")
            )
            ->paginate(20);
        return TaskResource::collection($tasks);
    }

    #[OA\Post(
        path: '/api/v1/tasks',
        summary: 'タスク登録',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'priority', 'status'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: '提案書作成'),
                    new OA\Property(property: 'priority', type: 'string', enum: ['高', '中', '低']),
                    new OA\Property(property: 'status', type: 'string', enum: ['未着手', '進行中', '完了']),
                    new OA\Property(property: 'due_date', type: 'string', format: 'date', example: '2026-04-30'),
                    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'deal_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'description', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: '登録成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/tasks/{id}',
        summary: 'タスク詳細取得',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'タスクID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function show(Task $task)
    {
        $task->load(['customer', 'deal', 'user']);
        return new TaskResource($task);
    }

    #[OA\Put(
        path: '/api/v1/tasks/{id}',
        summary: 'タスク更新',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'タスクID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '更新成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

    #[OA\Patch(
        path: '/api/v1/tasks/{id}/status',
        summary: 'タスクステータス更新',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'タスクID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['未着手', '進行中', '完了']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: '更新成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function updateStatus(Request $request, Task $task)
    {
        $request->validate(
            ['status' => 'required|in:未着手,進行中,完了'],
            ['status.required' => 'ステータスは必須です', 'status.in' => 'ステータスの値が正しくありません']
        );
        $task->update(['status' => $request->status]);
        return new TaskResource($task);
    }

    #[OA\Delete(
        path: '/api/v1/tasks/{id}',
        summary: 'タスク削除',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'タスクID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: '削除成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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
