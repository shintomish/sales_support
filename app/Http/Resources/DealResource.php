<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'customer_id'         => $this->customer_id,
            'contact_id'          => $this->contact_id,
            'title'               => $this->title,
            'amount'              => (int) $this->amount,
            'status'              => $this->status,
            'probability'         => $this->probability,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'actual_close_date'   => $this->actual_close_date?->toDateString(),
            'notes'               => $this->notes,
            'created_at'          => $this->created_at?->toDateTimeString(),

            'customer' => $this->whenLoaded('customer', fn() => [
                'id'           => $this->customer->id,
                'company_name' => $this->customer->company_name,
            ]),
            'contact' => $this->whenLoaded('contact', fn() => $this->contact ? [
                'id'       => $this->contact->id,
                'name'     => $this->contact->name,
                'position' => $this->contact->position,
            ] : null),
            'user' => $this->whenLoaded('user', fn() => $this->user ? [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'activities' => $this->whenLoaded('activities', fn() =>
                $this->activities->map(fn($a) => [
                    'id'            => $a->id,
                    'type'          => $a->type,
                    'subject'       => $a->subject,
                    'content'       => $a->description ?? null,
                    'activity_date' => $a->activity_date?->toDateString(),
                ])
            ),
        ];
    }
}
