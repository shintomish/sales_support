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
        'industry',
        'employee_count',
        'address',
        'phone',
        'fax',
        'website',
        'notes',
        'is_supplier',
        'is_customer',
        'invoice_number',
        'payment_site',
        'vendor_payment_site',
        'primary_contact_id',
    ];

    protected $casts = [
        'is_supplier' => 'boolean',
        'is_customer' => 'boolean',
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
