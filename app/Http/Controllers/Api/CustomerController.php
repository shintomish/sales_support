<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CustomerController extends Controller
{
    #[OA\Get(
        path: '/api/v1/customers',
        summary: '顧客一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: '会社名・業種で検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'industry', in: 'query', required: false, description: '業種フィルター', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'ページ番号', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/customers',
        summary: '顧客登録',
        security: [['bearerAuth' => []]],
        tags: ['Customers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['company_name'],
                properties: [
                    new OA\Property(property: 'company_name', type: 'string', example: '株式会社サンプル'),
                    new OA\Property(property: 'industry', type: 'string', example: 'IT'),
                    new OA\Property(property: 'phone', type: 'string', example: '03-1234-5678'),
                    new OA\Property(property: 'address', type: 'string', example: '東京都千代田区1-1-1'),
                    new OA\Property(property: 'employee_count', type: 'integer', example: 100),
                    new OA\Property(property: 'website', type: 'string', example: 'https://example.com'),
                    new OA\Property(property: 'notes', type: 'string', example: '備考'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: '登録成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/customers/{id}',
        summary: '顧客詳細取得',
        security: [['bearerAuth' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '顧客ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '顧客が見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function show(Customer $customer)
    {
        $customer->load(['contacts', 'deals']);
        return new CustomerResource($customer);
    }

    #[OA\Put(
        path: '/api/v1/customers/{id}',
        summary: '顧客更新',
        security: [['bearerAuth' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '顧客ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['company_name'],
                properties: [
                    new OA\Property(property: 'company_name', type: 'string', example: '株式会社サンプル'),
                    new OA\Property(property: 'industry', type: 'string', example: 'IT'),
                    new OA\Property(property: 'phone', type: 'string', example: '03-1234-5678'),
                    new OA\Property(property: 'address', type: 'string', example: '東京都千代田区1-1-1'),
                    new OA\Property(property: 'employee_count', type: 'integer', example: 100),
                    new OA\Property(property: 'website', type: 'string', example: 'https://example.com'),
                    new OA\Property(property: 'notes', type: 'string', example: '備考'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: '更新成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
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

    #[OA\Delete(
        path: '/api/v1/customers/{id}',
        summary: '顧客削除',
        security: [['bearerAuth' => []]],
        tags: ['Customers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '顧客ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: '削除成功'),
            new OA\Response(response: 404, description: '顧客が見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function destroy(Customer $customer)
    {
        $customer->delete();
        return response()->json(null, 204);
    }

    #[OA\Get(
        path: '/api/v1/customers/industries',
        summary: '業種一覧取得（フィルター用）',
        security: [['bearerAuth' => []]],
        tags: ['Customers'],
        responses: [
            new OA\Response(response: 200, description: '業種の配列'),
        ]
    )]
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
