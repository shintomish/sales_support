<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Http\Resources\DealResource;
use Illuminate\Http\Request;

class DealController extends Controller
{
    public function index()
    {
        $deals = Deal::paginate(20);
        return DealResource::collection($deals);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'title' => 'required|string|max:255',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'required|in:新規,提案,交渉,成約,失注',
            'expected_close_date' => 'nullable|date',
        ]);
        
        // user_id を追加
        $validated['user_id'] = $request->user()->id;

        $deal = Deal::create($validated);
        return new DealResource($deal);
    }

    public function show(Deal $deal)
    {
        return new DealResource($deal);
    }

    public function update(Request $request, Deal $deal)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'title' => 'required|string|max:255',
            'amount' => 'nullable|numeric|min:0',
            'status' => 'required|in:新規,提案,交渉,成約,失注',
            'expected_close_date' => 'nullable|date',
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