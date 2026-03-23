<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Http\Resources\DealResource;
use Illuminate\Http\Request;

class DealController extends Controller
{
    public function index(Request $request)
    {
        $deals = Deal::with(['customer', 'contact'])
            ->where('deal_type', 'general')
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
            ->paginate(20);
        return DealResource::collection($deals);
    }

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

    public function show(Deal $deal)
    {
        $deal->load(['customer', 'contact', 'user', 'activities']);
        return new DealResource($deal);
    }

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
