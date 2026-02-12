<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Customer;
use App\Models\Contact;
use App\Http\Requests\DealRequest;
use Illuminate\Http\Request;

class DealController extends Controller
{
    // 商談一覧
    public function index(Request $request)
    {
        $query = Deal::with(['customer', 'contact', 'user']);

        // 検索
        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->search . '%')
                  ->orWhereHas('customer', function($q) use ($request) {
                      $q->where('company_name', 'like', '%' . $request->search . '%');
                  });
        }

        // ステータスフィルター
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $deals = $query->orderBy('created_at', 'desc')->paginate(10);

        return view('deals.index', compact('deals'));
    }

    // 商談詳細
    public function show(Deal $deal)
    {
        $deal->load(['customer', 'contact', 'user', 'activities']);
        return view('deals.show', compact('deal'));
    }

    // 商談登録フォーム
    public function create(Request $request)
    {
        $customers = Customer::orderBy('company_name')->get();
        $contacts  = collect();

        // 顧客が指定されている場合、担当者を取得
        if ($request->filled('customer_id')) {
            $contacts = Contact::where('customer_id', $request->customer_id)->get();
        }

        return view('deals.create', compact('customers', 'contacts'));
    }

    // 商談登録処理
    public function store(DealRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = 1; // 認証実装後に変更

        Deal::create($data);

        return redirect()->route('deals.index')
                         ->with('success', '商談を登録しました。');
    }

    // 商談編集フォーム
    public function edit(Deal $deal)
    {
        $customers = Customer::orderBy('company_name')->get();
        $contacts  = Contact::where('customer_id', $deal->customer_id)->get();

        return view('deals.edit', compact('deal', 'customers', 'contacts'));
    }

    // 商談更新処理
    public function update(DealRequest $request, Deal $deal)
    {
        $deal->update($request->validated());

        return redirect()->route('deals.show', $deal)
                         ->with('success', '商談情報を更新しました。');
    }

    // 商談削除処理
    public function destroy(Deal $deal)
    {
        $deal->delete();

        return redirect()->route('deals.index')
                         ->with('success', '商談を削除しました。');
    }

    // 顧客に紐づく担当者をAjaxで取得
    public function getContacts(Request $request)
    {
        $contacts = Contact::where('customer_id', $request->customer_id)
                           ->get(['id', 'name', 'position']);

        return response()->json($contacts);
    }
}