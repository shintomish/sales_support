<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Http\Requests\CustomerRequest;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    // 顧客一覧
    public function index(Request $request)
    {
        $query = Customer::query();

        if ($request->filled('search')) {
            $query->where('company_name', 'like', '%' . $request->search . '%')
                  ->orWhere('industry', 'like', '%' . $request->search . '%');
        }

        $customers = $query->orderBy('created_at', 'desc')->paginate(10);

        return view('customers.index', compact('customers'));
    }

    // 顧客詳細
    public function show(Customer $customer)
    {
        $customer->load(['contacts', 'deals', 'activities']);
        return view('customers.show', compact('customer'));
    }

    // 顧客登録フォーム
    public function create()
    {
        return view('customers.create');
    }

    // 顧客登録処理
    public function store(CustomerRequest $request)
    {
        Customer::create($request->validated());
        return redirect()->route('customers.index')
                         ->with('success', '顧客を登録しました。');
    }

    // 顧客編集フォーム
    public function edit(Customer $customer)
    {
        return view('customers.edit', compact('customer'));
    }

    // 顧客更新処理
    public function update(CustomerRequest $request, Customer $customer)
    {
        $customer->update($request->validated());
        return redirect()->route('customers.show', $customer)
                         ->with('success', '顧客情報を更新しました。');
    }

    // 顧客削除処理
    public function destroy(Customer $customer)
    {
        $customer->delete();
        return redirect()->route('customers.index')
                         ->with('success', '顧客を削除しました。');
    }
}