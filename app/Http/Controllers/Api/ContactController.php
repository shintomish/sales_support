<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Http\Resources\ContactResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ContactController extends Controller
{
    #[OA\Get(
        path: '/api/v1/contacts',
        summary: '担当者一覧取得',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: '氏名・部署・役職・会社名で検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: '顧客IDで絞り込み', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'ページ番号', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function index(Request $request)
    {
        $query = Contact::with('customer')
            ->when($request->search, fn($q, $s) =>
                $q->where('name', 'like', "%{$s}%")
                ->orWhere('department', 'like', "%{$s}%")
                ->orWhere('position', 'like', "%{$s}%")
                ->orWhereHas('customer', fn($q) =>
                    $q->where('company_name', 'like', "%{$s}%")
                )
            )
            ->when($request->customer_id, fn($q, $id) =>
                $q->where('customer_id', $id)
            );

        // 会社名ソート用 JOIN
        if ($request->get('sort_by') === 'company_name') {
            $query->leftJoin('customers', 'contacts.customer_id', '=', 'customers.id')
                  ->select('contacts.*');
        }

        if ($request->get('sort_by')) {
            [$sortCol, $sortDir] = $this->resolveSort($request, [
                'name'         => 'contacts.name',
                'company_name' => 'customers.company_name',
                'department'   => 'contacts.department',
                'position'     => 'contacts.position',
                'email'        => 'contacts.email',
            ], 'contacts.id', 'desc');
            $query->orderBy($sortCol, $sortDir);
        } else {
            $query->orderBy('contacts.id', 'desc');
        }

        $contacts = $query->paginate(20);
        return ContactResource::collection($contacts);
    }

    #[OA\Post(
        path: '/api/v1/contacts',
        summary: '担当者登録',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['customer_id', 'name'],
                properties: [
                    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', example: 'xxxx-xxxx'),
                    new OA\Property(property: 'name', type: 'string', example: '山田 太郎'),
                    new OA\Property(property: 'department', type: 'string', example: '営業部'),
                    new OA\Property(property: 'position', type: 'string', example: '部長'),
                    new OA\Property(property: 'email', type: 'string', example: 'yamada@example.com'),
                    new OA\Property(property: 'phone', type: 'string', example: '03-1234-5678'),
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
            'customer_id' => 'required|exists:customers,id',
            'name'        => 'required|string|max:255',
            'department'  => 'nullable|string|max:100',
            'position'    => 'nullable|string|max:100',
            'email'       => 'nullable|email:rfc|max:255|unique:contacts,email',
            'phone'       => ['nullable', 'string', 'max:20', 'regex:/^[\d\-\+\(\)\s]+$/'],
            'notes'       => 'nullable|string|max:2000',
        ], $this->messages());

        $contact = Contact::create($validated);
        return new ContactResource($contact);
    }

    #[OA\Get(
        path: '/api/v1/contacts/{id}',
        summary: '担当者詳細取得',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '担当者ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: '成功'),
            new OA\Response(response: 404, description: '担当者が見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function show(Contact $contact)
    {
        $contact->load(['customer', 'deals', 'activities']);
        return new ContactResource($contact);
    }

    #[OA\Put(
        path: '/api/v1/contacts/{id}',
        summary: '担当者更新',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '担当者ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['customer_id', 'name'],
                properties: [
                    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string', example: '山田 太郎'),
                    new OA\Property(property: 'department', type: 'string', example: '営業部'),
                    new OA\Property(property: 'position', type: 'string', example: '部長'),
                    new OA\Property(property: 'email', type: 'string', example: 'yamada@example.com'),
                    new OA\Property(property: 'phone', type: 'string', example: '03-1234-5678'),
                    new OA\Property(property: 'notes', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: '更新成功'),
            new OA\Response(response: 422, description: 'バリデーションエラー'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function update(Request $request, Contact $contact)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'name'        => 'required|string|max:255',
            'department'  => 'nullable|string|max:100',
            'position'    => 'nullable|string|max:100',
            'email'       => 'nullable|email:rfc|max:255|unique:contacts,email,' . $contact->id,
            'phone'       => ['nullable', 'string', 'max:20', 'regex:/^[\d\-\+\(\)\s]+$/'],
            'notes'       => 'nullable|string|max:2000',
        ], $this->messages());

        $contact->update($validated);
        return new ContactResource($contact);
    }

    #[OA\Delete(
        path: '/api/v1/contacts/{id}',
        summary: '担当者削除',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: '担当者ID', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: '削除成功'),
            new OA\Response(response: 404, description: '担当者が見つかりません'),
            new OA\Response(response: 401, description: '認証エラー'),
        ]
    )]
    public function destroy(Contact $contact)
    {
        $contact->delete();
        return response()->json(null, 204);
    }

    private function messages(): array
    {
        return [
            'customer_id.required' => '顧客を選択してください',
            'customer_id.exists'   => '選択された顧客が存在しません',
            'name.required'        => '氏名は必須です',
            'name.max'             => '氏名は255文字以内で入力してください',
            'department.max'       => '部署は100文字以内で入力してください',
            'position.max'         => '役職は100文字以内で入力してください',
            'email.email'          => 'メールアドレスの形式が正しくありません',
            'email.max'            => 'メールアドレスは255文字以内で入力してください',
            'email.unique'         => 'このメールアドレスはすでに登録されています',
            'phone.regex'          => '電話番号の形式が正しくありません（例: 03-1234-5678）',
            'phone.max'            => '電話番号は20文字以内で入力してください',
            'notes.max'            => '備考は2000文字以内で入力してください',
        ];
    }
}
