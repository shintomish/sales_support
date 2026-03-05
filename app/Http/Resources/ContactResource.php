<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'customer_id' => $this->customer_id,
            'name'        => $this->name,
            'department'  => $this->department,
            'position'    => $this->position,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'notes'       => $this->notes,
            'created_at'  => $this->created_at?->toDateTimeString(),
            'updated_at'  => $this->updated_at?->toDateTimeString(),

            'customer' => $this->whenLoaded('customer', fn() => [
                'id'           => $this->customer->id,
                'company_name' => $this->customer->company_name,
            ]),
            'deals' => $this->whenLoaded('deals', fn() =>
                $this->deals->map(fn($d) => [
                    'id'                  => $d->id,
                    'title'               => $d->title,
                    'amount'              => $d->amount,
                    'status'              => $d->status,
                    'probability'         => $d->probability ?? null,
                    'expected_close_date' => $d->expected_close_date?->toDateString(),
                ])
            ),
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
