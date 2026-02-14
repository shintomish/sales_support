<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'   => ['required', 'exists:customers,id'],
            'contact_id'    => ['nullable', 'exists:contacts,id'],
            'deal_id'       => ['nullable', 'exists:deals,id'],
            'type'          => ['required', 'in:訪問,電話,メール,その他'],
            'subject'       => ['required', 'string', 'max:255'],
            'content'       => ['nullable', 'string'],
            'activity_date' => ['required', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id'   => '顧客',
            'contact_id'    => '担当者',
            'deal_id'       => '商談',
            'type'          => '活動種別',
            'subject'       => '件名',
            'content'       => '内容',
            'activity_date' => '活動日',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required'   => '顧客を選択してください。',
            'type.required'          => '活動種別を選択してください。',
            'subject.required'       => '件名は必須です。',
            'activity_date.required' => '活動日は必須です。',
        ];
    }
}
