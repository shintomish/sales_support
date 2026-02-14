<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Contact;
use App\Models\Deal;
use App\Http\Requests\ActivityRequest;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    // 活動履歴一覧
    public function index(Request $request)
    {
        $query = Activity::with(['customer', 'contact', 'deal', 'user']);

        // 検索
        if ($request->filled('search')) {
            $query->where('subject', 'like', '%' . $request->search . '%')
                  ->orWhere('content', 'like', '%' . $request->search . '%')
                  ->orWhereHas('customer', function($q) use ($request) {
                      $q->where('company_name', 'like', '%' . $request->search . '%');
                  });
        }

        // 種別フィルター
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // 顧客フィルター
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // 期間フィルター
        if ($request->filled('date_from')) {
            $query->where('activity_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('activity_date', '<=', $request->date_to);
        }

        $activities = $query->orderBy('activity_date', 'desc')->paginate(15);
        $customers  = Customer::orderBy('company_name')->get();

        return view('activities.index', compact('activities', 'customers'));
    }

    // 活動履歴詳細
    public function show(Activity $activity)
    {
        $activity->load(['customer', 'contact', 'deal', 'user']);
        return view('activities.show', compact('activity'));
    }

    // 活動履歴登録フォーム
    public function create(Request $request)
    {
        $customers  = Customer::orderBy('company_name')->get();
        $contacts   = collect();
        $deals      = collect();
        $customerId = $request->customer_id;

        if ($request->filled('customer_id')) {
            $contacts = Contact::where('customer_id', $request->customer_id)->get();
            $deals    = Deal::where('customer_id', $request->customer_id)
                            ->whereNotIn('status', ['成約', '失注'])
                            ->get();
        }

        return view('activities.create', compact('customers', 'contacts', 'deals', 'customerId'));
    }

    // 活動履歴登録処理
    public function store(ActivityRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = 1; // 認証実装後に変更

        Activity::create($data);

        return redirect()->route('activities.index')
                         ->with('success', '活動履歴を登録しました。');
    }

    // 活動履歴編集フォーム
    public function edit(Activity $activity)
    {
        $customers = Customer::orderBy('company_name')->get();
        $contacts  = Contact::where('customer_id', $activity->customer_id)->get();
        $deals     = Deal::where('customer_id', $activity->customer_id)
                         ->whereNotIn('status', ['成約', '失注'])
                         ->get();

        return view('activities.edit', compact('activity', 'customers', 'contacts', 'deals'));
    }

    // 活動履歴更新処理
    public function update(ActivityRequest $request, Activity $activity)
    {
        $activity->update($request->validated());

        return redirect()->route('activities.show', $activity)
                         ->with('success', '活動履歴を更新しました。');
    }

    // 活動履歴削除処理
    public function destroy(Activity $activity)
    {
        $activity->delete();

        return redirect()->route('activities.index')
                         ->with('success', '活動履歴を削除しました。');
    }

    // 顧客に紐づく担当者・商談をAjaxで取得
    public function getCustomerData(Request $request)
    {
        $contacts = Contact::where('customer_id', $request->customer_id)
                           ->get(['id', 'name', 'position']);
        $deals    = Deal::where('customer_id', $request->customer_id)
                        ->whereNotIn('status', ['成約', '失注'])
                        ->get(['id', 'title']);

        return response()->json(compact('contacts', 'deals'));
    }
}
