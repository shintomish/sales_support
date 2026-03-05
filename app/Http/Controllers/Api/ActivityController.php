<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $activities = Activity::with(['customer', 'contact', 'deal'])
            ->when($request->search, fn($q, $s) =>
                $q->where('subject', 'like', "%{$s}%")
                ->orWhere('content', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->type, fn($q, $t) => $q->where('type', $t))
            ->when($request->customer_id, fn($q, $id) => $q->where('customer_id', $id))
            ->when($request->date_from, fn($q, $d) => $q->where('activity_date', '>=', $d))
            ->when($request->date_to,   fn($q, $d) => $q->where('activity_date', '<=', $d))
            ->orderBy('activity_date', 'desc')
            ->paginate(20);
        return ActivityResource::collection($activities);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id'   => 'required|exists:customers,id',
            'contact_id'    => 'nullable|exists:contacts,id',  // ★ 追加
            'deal_id'       => 'nullable|exists:deals,id',     // ★ 追加
            'type'          => 'required|in:訪問,電話,メール,その他',
            'subject'       => 'required|string|max:255',
            'content'       => 'nullable|string',              // ★ descriptionからcontentに統一
            'activity_date' => 'required|date',
        ]);
        $validated['user_id'] = $request->user()->id;
        $activity = Activity::create($validated);
        return new ActivityResource($activity);
    }

    public function show(Activity $activity)
    {
        $activity->load(['customer', 'contact', 'deal', 'user']); // ★ eager load追加
        return new ActivityResource($activity);
    }

    public function update(Request $request, Activity $activity)
    {
        $validated = $request->validate([
            'customer_id'   => 'required|exists:customers,id',
            'contact_id'    => 'nullable|exists:contacts,id',  // ★ 追加
            'deal_id'       => 'nullable|exists:deals,id',     // ★ 追加
            'type'          => 'required|in:訪問,電話,メール,その他',
            'subject'       => 'required|string|max:255',
            'content'       => 'nullable|string',
            'activity_date' => 'required|date',
        ]);
        $activity->update($validated);
        return new ActivityResource($activity);
    }
    public function destroy(Activity $activity)
    {
        $activity->delete();
        return response()->json(null, 204);
    }
}
