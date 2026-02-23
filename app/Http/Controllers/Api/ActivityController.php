<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Http\Resources\ActivityResource;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index()
    {
        $activities = Activity::paginate(20);
        return ActivityResource::collection($activities);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'subject' => 'required|string|max:255',  // ← 追加
            'type' => 'required|in:電話,メール,訪問,その他',
            'description' => 'required|string',
            'activity_date' => 'required|date',
        ]);
        // user_id を追加
        $validated['user_id'] = $request->user()->id;

        $activity = Activity::create($validated);
        return new ActivityResource($activity);
    }

    public function show(Activity $activity)
    {
        return new ActivityResource($activity);
    }

    public function update(Request $request, Activity $activity)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'type' => 'required|in:電話,メール,訪問,その他',
            'description' => 'required|string',
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