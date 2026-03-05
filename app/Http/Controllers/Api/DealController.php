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
            ->when($request->search, fn($q, $s) =>
                $q->where('title', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->status, fn($q, $s) =>
                $q->where('status', $s)
            )
            ->paginate(20);
        return DealResource::collection($deals);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id'         => 'required|exists:customers,id',
            'contact_id'          => 'nullable|exists:contacts,id',  // ★ 追加
            'title'               => 'required|string|max:255',
            'amount'              => 'nullable|numeric|min:0',
            'status'              => 'required|in:新規,提案,交渉,成約,失注',
            'probability'         => 'nullable|integer|min:0|max:100', // ★ 追加
            'expected_close_date' => 'nullable|date',
            'actual_close_date'   => 'nullable|date',                 // ★ 追加
            'notes'               => 'nullable|string',               // ★ 追加
        ]);
        $validated['user_id'] = $request->user()->id;
        $deal = Deal::create($validated);
        return new DealResource($deal);
    }

    public function show(Deal $deal)
    {
        $deal->load(['customer', 'contact', 'user', 'activities']); // ★ eager load追加
        return new DealResource($deal);
    }

    public function update(Request $request, Deal $deal)
    {
        $validated = $request->validate([
            'customer_id'         => 'required|exists:customers,id',
            'contact_id'          => 'nullable|exists:contacts,id',  // ★ 追加
            'title'               => 'required|string|max:255',
            'amount'              => 'nullable|numeric|min:0',
            'status'              => 'required|in:新規,提案,交渉,成約,失注',
            'probability'         => 'nullable|integer|min:0|max:100', // ★ 追加
            'expected_close_date' => 'nullable|date',
            'actual_close_date'   => 'nullable|date',                 // ★ 追加
            'notes'               => 'nullable|string',               // ★ 追加
        ]);
        $deal->update($validated);
        return new DealResource($deal);
    }

    public function destroy(Deal $deal)
    {
        $deal->delete();
        return response()->json(null, 204);
    }
}
