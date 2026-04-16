<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'company_name'         => $this->company_name,
            'industry'             => $this->industry,
            'employee_count'       => $this->employee_count,
            'phone'                => $this->phone,
            'fax'                  => $this->fax,
            'address'              => $this->address,
            'website'              => $this->website,
            'notes'                => $this->notes,
            'is_supplier'          => (bool) $this->is_supplier,
            'is_customer'          => (bool) $this->is_customer,
            'invoice_number'       => $this->invoice_number,
            'payment_site'         => $this->payment_site,
            'vendor_payment_site'  => $this->vendor_payment_site,
            'primary_contact_id'   => $this->primary_contact_id,
            'primary_contact'      => $this->whenLoaded('primaryContact', fn() => $this->primaryContact ? [
                'id'    => $this->primaryContact->id,
                'name'  => $this->primaryContact->name,
                'email' => $this->primaryContact->email,
            ] : null),
            'created_at'           => $this->created_at?->toDateTimeString(),
            'updated_at'           => $this->updated_at?->toDateTimeString(),

            // ★ 詳細ページ用（リレーション）
            'contacts' => $this->whenLoaded('contacts', fn() =>
                $this->contacts->map(fn($c) => [
                    'id'         => $c->id,
                    'name'       => $c->name,
                    'department' => $c->department ?? null,
                    'position'   => $c->position,
                    'email'      => $c->email,
                    'phone'      => $c->phone,
                ])
            ),
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
        ];
    }

    public function show(Customer $customer)
    {
        $customer->load(['contacts', 'deals']); // ★ 追加
        return new CustomerResource($customer);
    }

    public function index(Request $request)
    {
        $customers = Customer::query()
            ->when($request->search, fn($q, $s) =>
                $q->where('company_name', 'like', "%{$s}%")
                ->orWhere('industry', 'like', "%{$s}%")
            )
            ->paginate(20);
        return CustomerResource::collection($customers);
    }

}
