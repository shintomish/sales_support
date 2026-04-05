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
        $userFilter = $this->resolveUserFilter($request);

        $activities = Activity::with(['customer', 'contact', 'deal'])
            ->when($userFilter,            fn($q, $id) => $q->where('user_id', $id))
            ->when($request->search, fn($q, $s) =>
                $q->where('subject', 'like', "%{$s}%")
                ->orWhere('content', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->type,        fn($q, $t) => $q->where('type', $t))
            ->when($request->customer_id, fn($q, $id) => $q->where('customer_id', $id))
            ->when($request->date_from,   fn($q, $d) => $q->where('activity_date', '>=', $d))
            ->when($request->date_to,     fn($q, $d) => $q->where('activity_date', '<=', $d))
            ->orderBy('activity_date', 'desc')
            ->paginate(20);
        return ActivityResource::collection($activities);
    }

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

    public function show(Activity $activity)
    {
        $activity->load(['customer', 'contact', 'deal', 'user']);
        return new ActivityResource($activity);
    }

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
