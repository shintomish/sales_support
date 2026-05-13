<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Customer extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'company_name',
        'invoice_code',
        'industry',
        'employee_count',
        'address',
        'postal_code',
        'phone',
        'fax',
        'website',
        'notes',
        'is_supplier',
        'is_customer',
        'invoice_number',
        'payment_site',
        'vendor_payment_site',
        'invoice_delivery_method',
        'quotation_language',
        'primary_contact_id',
        'secondary_contact_ids',
    ];

    protected $casts = [
        'is_supplier'           => 'boolean',
        'is_customer'           => 'boolean',
        'quotation_language'    => 'boolean',
        'secondary_contact_ids' => 'array',
    ];

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    public function primaryContact()
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
