<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessCard extends Model
{
    use BelongsToTenant;
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'customer_id',
        'contact_id',
        'ocr_text',
        'company_name',
        'person_name',
        'department',
        'position',
        'postal_code',
        'address',
        'phone',
        'mobile',
        'fax',
        'email',
        'website',
        'image_path',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // リレーション
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
