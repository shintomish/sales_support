<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name'   => ['required', 'string', 'max:255'],
            'industry'       => ['nullable', 'string', 'max:255'],
            'employee_count' => ['nullable', 'integer', 'min:1'],
            'address'        => ['nullable', 'string', 'max:255'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'website'        => ['nullable', 'url', 'max:255'],
            'notes'          => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'company_name'   => '会社名',
            'industry'       => '業種',
            'employee_count' => '従業員数',
            'address'        => '住所',
            'phone'          => '電話番号',
            'website'        => 'ウェブサイト',
            'notes'          => '備考',
        ];
    }

    public function messages(): array
    {
        return [
            'company_name.required' => '会社名は必須です。',
            'company_name.max'      => '会社名は255文字以内で入力してください。',
            'employee_count.integer' => '従業員数は整数で入力してください。',
            'employee_count.min'    => '従業員数は1以上で入力してください。',
            'website.url'           => 'ウェブサイトは正しいURL形式で入力してください。',
        ];
    }
}