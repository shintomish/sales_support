<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * 顧客一覧取得
     */
    public function index()
    {
        $customers = Customer::paginate(20);
        return CustomerResource::collection($customers);
    }

    /**
     * 顧客新規作成
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'industry' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        $customer = Customer::create($validated);
        return new CustomerResource($customer);
    }

    /**
     * 顧客詳細取得
     */
    public function show(Customer $customer)
    {
        return new CustomerResource($customer);
    }

    /**
     * 顧客更新
     */
    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'industry' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        $customer->update($validated);
        return new CustomerResource($customer);
    }

    /**
     * 顧客削除
     */
    public function destroy(Customer $customer)
    {
        $customer->delete();
        return response()->json(null, 204);
    }
}