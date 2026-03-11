<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::query()
            ->when($request->search, fn($q, $s) =>
                $q->where('company_name', 'like', "%{$s}%")
                ->orWhere('industry', 'like', "%{$s}%")
            )
            ->when($request->industry, fn($q, $i) =>
                $q->where('industry', 'like', "%{$i}%")
            )
            ->paginate(20);
        return CustomerResource::collection($customers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'company_name'   => 'required|string|max:255|unique:customers,company_name',
            'industry'       => 'nullable|string|max:100',
            'phone'          => ['nullable', 'string', 'max:20', 'regex:/^[\d\-\+\(\)\s]+$/'],
            'address'        => 'nullable|string|max:500',
            'employee_count' => 'nullable|integer|min:0|max:9999999',
            'website'        => 'nullable|url|max:255',
            'notes'          => 'nullable|string|max:2000',
        ], $this->messages());

        $customer = Customer::create($validated);
        return new CustomerResource($customer);
    }

    public function show(Customer $customer)
    {
        $customer->load(['contacts', 'deals']);
        return new CustomerResource($customer);
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'company_name'   => 'required|string|max:255|unique:customers,company_name,' . $customer->id,
            'industry'       => 'nullable|string|max:100',
            'phone'          => ['nullable', 'string', 'max:20', 'regex:/^[\d\-\+\(\)\s]+$/'],
            'address'        => 'nullable|string|max:500',
            'employee_count' => 'nullable|integer|min:0|max:9999999',
            'website'        => 'nullable|url|max:255',
            'notes'          => 'nullable|string|max:2000',
        ], $this->messages());

        $customer->update($validated);
        return new CustomerResource($customer);
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return response()->json(null, 204);
    }

    // 業種一覧取得（フィルター用）
    public function industries()
    {
        $industries = Customer::whereNotNull('industry')
            ->where('industry', '!=', '')
            ->distinct()
            ->orderBy('industry')
            ->pluck('industry');
        return response()->json($industries);
    }

    private function messages(): array
    {
        return [
            'company_name.required' => '会社名は必須です',
            'company_name.max'      => '会社名は255文字以内で入力してください',
            'company_name.unique'   => 'この会社名はすでに登録されています',
            'industry.max'          => '業種は100文字以内で入力してください',
            'phone.regex'           => '電話番号の形式が正しくありません（例: 03-1234-5678）',
            'phone.max'             => '電話番号は20文字以内で入力してください',
            'address.max'           => '住所は500文字以内で入力してください',
            'employee_count.integer'=> '従業員数は整数で入力してください',
            'employee_count.min'    => '従業員数は0以上で入力してください',
            'employee_count.max'    => '従業員数の値が大きすぎます',
            'website.url'           => 'WebサイトのURLの形式が正しくありません（例: https://example.com）',
            'website.max'           => 'WebサイトのURLは255文字以内で入力してください',
            'notes.max'             => '備考は2000文字以内で入力してください',
        ];
    }
}
