<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'name'        => ['required', 'string', 'max:255'],
            'department'  => ['nullable', 'string', 'max:255'],
            'position'    => ['nullable', 'string', 'max:255'],
            'email'       => ['nullable', 'email', 'max:255'],
            'phone'       => ['nullable', 'string', 'max:20'],
            'notes'       => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => '顧客',
            'name'        => '氏名',
            'department'  => '部署',
            'position'    => '役職',
            'email'       => 'メールアドレス',
            'phone'       => '電話番号',
            'notes'       => '備考',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => '顧客を選択してください。',
            'name.required'        => '氏名は必須です。',
            'email.email'          => '正しいメールアドレス形式で入力してください。',
        ];
    }
}