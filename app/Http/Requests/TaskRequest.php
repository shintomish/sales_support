<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'deal_id'     => ['nullable', 'exists:deals,id'],
            'due_date'    => ['nullable', 'date'],
            'status'      => ['required', 'in:未着手,進行中,完了'],
            'priority'    => ['required', 'in:高,中,低'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title'       => 'タイトル',
            'description' => '詳細',
            'customer_id' => '顧客',
            'deal_id'     => '商談',
            'due_date'    => '期限日',
            'status'      => 'ステータス',
            'priority'    => '優先度',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'    => 'タイトルは必須です。',
            'status.required'   => 'ステータスを選択してください。',
            'priority.required' => '優先度を選択してください。',
        ];
    }
}
