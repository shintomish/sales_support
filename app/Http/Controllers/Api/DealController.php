<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Http\Resources\DealResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DealController extends Controller
{
    #[OA\Get(
        path: '/api/v1/deals',
        summary: '案件一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Deals'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: '案件名・会社名で検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'ステータスで絞り込み', schema: new OA\Schema(type: 'string', enum: ['新規', '提案', '交渉', '成約', '失注', '稼働中', '更新交渉中', '期限切れ'])),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: '顧客IDで絞り込み', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'amount_min', in: 'query', required: false, description: '金額（下限）', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'amount_max', in: 'query', required: false, description: '金額（上限）', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'ページ番号', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function index(Request $request)
    {
        $userFilter = $this->resolveUserFilter($request);

        $deals = Deal::with(['customer', 'contact'])
            ->where('deal_type', 'general')
            ->when($userFilter,            fn($q, $id) => $q->where('user_id', $id))
            ->when($request->search, fn($q, $s) =>
                $q->where('title', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->status,      fn($q, $s) => $q->where('status', $s))
            ->when($request->customer_id, fn($q, $id) => $q->where('customer_id', $id))
            ->when($request->amount_min,  fn($q, $v) => $q->where('amount', '>=', $v))
            ->when($request->amount_max,  fn($q, $v) => $q->where('amount', '<=', $v))
            ->orderBy(...$this->resolveSort($request, [
                'title'               => 'title',
                'amount'              => 'amount',
                'status'              => 'status',
                'expected_close_date' => 'expected_close_date',
                'created_at'          => 'created_at',
            ], 'created_at', 'desc'))
            ->paginate(20);
        return DealResource::collection($deals);
    }

    #[OA\Post(
        path: '/api/v1/deals',
        summary: '案件登録',
        security: [['bearerAuth' => []]],
        tags: ['Deals'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['customer_id', 'title', 'status'],
                properties: [
                    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'title', type: 'string', example: '新規SES案件'),
                    new OA\Property(property: 'amount', type: 'number', example: 500000),
                    new OA\Property(property: 'status', type: 'string', enum: ['新規', '提案', '交渉', '成約', '失注', '稼働中', '更新交渉中', '期限切れ']),
                    new OA\Property(property: 'probability', type: 'integer', example: 70),
                    new OA\Property(property: 'expected_close_date', type: 'string', format: 'date', example: '2026-05-31'),
                    new OA\Property(property: 'notes', type: 'string'),
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
            'customer_id'         => 'required|exists:customers,id',
            'contact_id'          => 'nullable|exists:contacts,id',
            'title'               => 'required|string|max:255',
            'amount'              => 'nullable|numeric|min:0|max:999999999999',
            'status'              => 'required|in:新規,提案,交渉,成約,失注,稼働中,更新交渉中,期限切れ',
            'probability'         => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'actual_close_date'   => 'nullable|date|after_or_equal:expected_close_date',
            'notes'               => 'nullable|string|max:2000',
        ], $this->messages());

        $validated['user_id']   = $request->user()->id;
        $validated['deal_type'] = 'general';
        $deal = Deal::create($validated);
        return new DealResource($deal);
    }

    #[OA\Get(
        path: '/api/v1/deals/{id}',
        summary: '案件詳細取得',
        security: [['bearerAuth' => []]],
        tags: ['Deals'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '案件ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '案件が見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function show(Deal $deal)
    {
        $deal->load(['customer', 'contact', 'user', 'activities']);
        return new DealResource($deal);
    }

    #[OA\Put(
        path: '/api/v1/deals/{id}',
        summary: '案件更新',
        security: [['bearerAuth' => []]],
        tags: ['Deals'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '案件ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '更新成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function update(Request $request, Deal $deal)
    {
        $validated = $request->validate([
            'customer_id'         => 'required|exists:customers,id',
            'contact_id'          => 'nullable|exists:contacts,id',
            'title'               => 'required|string|max:255',
            'amount'              => 'nullable|numeric|min:0|max:999999999999',
            'status'              => 'required|in:新規,提案,交渉,成約,失注,稼働中,更新交渉中,期限切れ',
            'probability'         => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'actual_close_date'   => 'nullable|date|after_or_equal:expected_close_date',
            'notes'               => 'nullable|string|max:2000',
        ], $this->messages());

        $deal->update($validated);
        return new DealResource($deal);
    }

    #[OA\Delete(
        path: '/api/v1/deals/{id}',
        summary: '案件削除',
        security: [['bearerAuth' => []]],
        tags: ['Deals'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '案件ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: '削除成功'),
            new OA\Response(response: 404, description: '案件が見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function destroy(Deal $deal)
    {
        $deal->delete();
        return response()->json(null, 204);
    }

    private function messages(): array
    {
        return [
            'customer_id.required'             => '顧客を選択してください',
            'customer_id.exists'               => '選択された顧客が存在しません',
            'contact_id.exists'                => '選択された担当者が存在しません',
            'title.required'                   => '商談名は必須です',
            'title.max'                        => '商談名は255文字以内で入力してください',
            'amount.numeric'                   => '金額は数値で入力してください',
            'amount.min'                       => '金額は0以上で入力してください',
            'amount.max'                       => '金額が大きすぎます',
            'status.required'                  => 'ステータスを選択してください',
            'status.in'                        => 'ステータスの値が正しくありません',
            'probability.integer'              => '成約確度は整数で入力してください',
            'probability.min'                  => '成約確度は0以上で入力してください',
            'probability.max'                  => '成約確度は100以下で入力してください',
            'expected_close_date.date'         => '予定成約日の形式が正しくありません',
            'actual_close_date.date'           => '実際の成約日の形式が正しくありません',
            'actual_close_date.after_or_equal' => '実際の成約日は予定成約日以降の日付を入力してください',
            'notes.max'                        => '備考は2000文字以内で入力してください',
        ];
    }
}
