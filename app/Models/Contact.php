<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Contact extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'customer_id', 'name', 'department',
        'position', 'email', 'phone', 'notes',
    ];

    public function customer()   { return $this->belongsTo(Customer::class); }
    public function deals()      { return $this->hasMany(Deal::class); }
    public function activities() { return $this->hasMany(Activity::class); }
}
