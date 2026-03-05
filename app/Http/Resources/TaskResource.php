<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'priority'    => $this->priority,
            'status'      => $this->status,
            'due_date'    => $this->due_date?->toDateString(),
            'customer_id' => $this->customer_id,
            'deal_id'     => $this->deal_id,
            'description' => $this->description,
            'created_at'  => $this->created_at?->toDateTimeString(),
            'customer'    => $this->whenLoaded('customer', fn() => $this->customer ? [
                'id'           => $this->customer->id,
                'company_name' => $this->customer->company_name,
            ] : null),
            'deal' => $this->whenLoaded('deal', fn() => $this->deal ? [
                'id'    => $this->deal->id,
                'title' => $this->deal->title,
            ] : null),
            'user' => $this->whenLoaded('user', fn() => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ] : null),
        ];
    }
}
