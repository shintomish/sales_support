<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Http\Resources\ContactResource;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $contacts = Contact::with('customer')
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
            )
            ->paginate(20);
        return ContactResource::collection($contacts);
    }

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

    public function show(Contact $contact)
    {
        $contact->load(['customer', 'deals', 'activities']);
        return new ContactResource($contact);
    }

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
