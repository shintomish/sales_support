<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'customer_id'   => $this->customer_id,
            'contact_id'    => $this->contact_id,
            'deal_id'       => $this->deal_id,
            'type'          => $this->type,
            'subject'       => $this->subject,
            'content'       => $this->content,
            'activity_date' => $this->activity_date?->toDateString(),
            'created_at'    => $this->created_at?->toDateTimeString(),

            'customer' => $this->whenLoaded('customer', fn() => [
                'id'           => $this->customer->id,
                'company_name' => $this->customer->company_name,
            ]),
            'contact' => $this->whenLoaded('contact', fn() => $this->contact ? [
                'id'   => $this->contact->id,
                'name' => $this->contact->name,
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
