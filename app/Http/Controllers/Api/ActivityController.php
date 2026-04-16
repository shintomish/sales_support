<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ActivityController extends Controller
{
    #[OA\Get(
        path: '/api/v1/activities',
        summary: '活動履歴一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Activities'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: '件名・内容・会社名で検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: '活動種別', schema: new OA\Schema(type: 'string', enum: ['訪問', '電話', 'メール', 'その他'])),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: '顧客IDで絞り込み', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: '期間（開始）', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: '期間（終了）', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function index(Request $request)
    {
        $userFilter = $this->resolveUserFilter($request);

        $query = Activity::with(['customer', 'contact', 'deal'])
            ->when($userFilter,            fn($q, $id) => $q->where('activities.user_id', $id))
            ->when($request->search, fn($q, $s) =>
                $q->where('activities.subject', 'like', "%{$s}%")
                ->orWhere('activities.content', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->type,        fn($q, $t) => $q->where('activities.type', $t))
            ->when($request->customer_id, fn($q, $id) => $q->where('activities.customer_id', $id))
            ->when($request->date_from,   fn($q, $d) => $q->where('activities.activity_date', '>=', $d))
            ->when($request->date_to,     fn($q, $d) => $q->where('activities.activity_date', '<=', $d));

        // 顧客名ソート用 JOIN
        if ($request->get('sort_by') === 'customer_name') {
            $query->leftJoin('customers', 'activities.customer_id', '=', 'customers.id')
                  ->select('activities.*');
        }

        $activities = $query->orderBy(...$this->resolveSort($request, [
                'activity_date' => 'activities.activity_date',
                'type'          => 'activities.type',
                'subject'       => 'activities.subject',
                'created_at'    => 'activities.created_at',
                'customer_name' => 'customers.company_name',
            ], 'activities.activity_date', 'desc'))
            ->paginate(50);
        return ActivityResource::collection($activities);
    }

    #[OA\Post(
        path: '/api/v1/activities',
        summary: '活動履歴登録',
        security: [['bearerAuth' => []]],
        tags: ['Activities'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['customer_id', 'type', 'subject', 'activity_date'],
                properties: [
                    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'deal_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string', enum: ['訪問', '電話', 'メール', 'その他']),
                    new OA\Property(property: 'subject', type: 'string', example: '初回訪問'),
                    new OA\Property(property: 'content', type: 'string', example: '新規案件の提案を行った'),
                    new OA\Property(property: 'activity_date', type: 'string', format: 'date', example: '2026-04-11'),
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
            'customer_id'   => 'required|exists:customers,id',
            'contact_id'    => 'nullable|exists:contacts,id',
            'deal_id'       => 'nullable|exists:deals,id',
            'type'          => 'required|in:訪問,電話,メール,その他',
            'subject'       => 'required|string|max:255',
            'content'       => 'nullable|string|max:5000',
            'activity_date' => 'required|date|before_or_equal:today',
        ], $this->messages());

        $validated['user_id'] = $request->user()->id;
        $activity = Activity::create($validated);
        return new ActivityResource($activity);
    }

    #[OA\Get(
        path: '/api/v1/activities/{id}',
        summary: '活動履歴詳細取得',
        security: [['bearerAuth' => []]],
        tags: ['Activities'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '活動履歴ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function show(Activity $activity)
    {
        $activity->load(['customer', 'contact', 'deal', 'user']);
        return new ActivityResource($activity);
    }

    #[OA\Put(
        path: '/api/v1/activities/{id}',
        summary: '活動履歴更新',
        security: [['bearerAuth' => []]],
        tags: ['Activities'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '活動履歴ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '更新成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function update(Request $request, Activity $activity)
    {
        $validated = $request->validate([
            'customer_id'   => 'required|exists:customers,id',
            'contact_id'    => 'nullable|exists:contacts,id',
            'deal_id'       => 'nullable|exists:deals,id',
            'type'          => 'required|in:訪問,電話,メール,その他',
            'subject'       => 'required|string|max:255',
            'content'       => 'nullable|string|max:5000',
            'activity_date' => 'required|date|before_or_equal:today',
        ], $this->messages());

        $activity->update($validated);
        return new ActivityResource($activity);
    }

    #[OA\Delete(
        path: '/api/v1/activities/{id}',
        summary: '活動履歴削除',
        security: [['bearerAuth' => []]],
        tags: ['Activities'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '活動履歴ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: '削除成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function destroy(Activity $activity)
    {
        $activity->delete();
        return response()->json(null, 204);
    }

    private function messages(): array
    {
        return [
            'customer_id.required'          => '顧客を選択してください',
            'customer_id.exists'            => '選択された顧客が存在しません',
            'contact_id.exists'             => '選択された担当者が存在しません',
            'deal_id.exists'                => '選択された商談が存在しません',
            'type.required'                 => '活動種別を選択してください',
            'type.in'                       => '活動種別の値が正しくありません',
            'subject.required'              => '件名は必須です',
            'subject.max'                   => '件名は255文字以内で入力してください',
            'content.max'                   => '内容は5000文字以内で入力してください',
            'activity_date.required'        => '活動日は必須です',
            'activity_date.date'            => '活動日の形式が正しくありません',
            'activity_date.before_or_equal' => '活動日は今日以前の日付を入力してください',
        ];
    }
}
