<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'customer_id' => $this->customer_id,
            'contact_id' => $this->contact_id,
            'company_name' => $this->company_name,
            'person_name' => $this->person_name,
            'department' => $this->department,
            'position' => $this->position,
            'postal_code' => $this->postal_code,
            'address' => $this->address,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'fax' => $this->fax,
            'email' => $this->email,
            'website' => $this->website,
            'image_path' => $this->image_path,
            'status' => $this->status,
            'ocr_text' => $this->ocr_text,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
