<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'         => ['required', 'exists:customers,id'],
            'contact_id'          => ['nullable', 'exists:contacts,id'],
            'title'               => ['required', 'string', 'max:255'],
            'amount'              => ['required', 'numeric', 'min:0'],
            'status'              => ['required', 'in:新規,提案,交渉,成約,失注'],
            'probability'         => ['required', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'actual_close_date'   => ['nullable', 'date'],
            'notes'               => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id'         => '顧客',
            'contact_id'          => '担当者',
            'title'               => '商談名',
            'amount'              => '予定金額',
            'status'              => 'ステータス',
            'probability'         => '成約確度',
            'expected_close_date' => '予定成約日',
            'actual_close_date'   => '実際の成約日',
            'notes'               => '備考',
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => '顧客を選択してください。',
            'title.required'       => '商談名は必須です。',
            'amount.required'      => '予定金額は必須です。',
            'amount.numeric'       => '予定金額は数値で入力してください。',
            'status.required'      => 'ステータスを選択してください。',
            'probability.min'      => '成約確度は0〜100で入力してください。',
            'probability.max'      => '成約確度は0〜100で入力してください。',
        ];
    }
}