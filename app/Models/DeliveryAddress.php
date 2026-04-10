<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryAddress extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'email',
        'name',
        'zip_code',
        'prefecture',
        'address',
        'tel',
        'occupation',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function sendHistories(): HasMany
    {
        return $this->hasMany(DeliverySendHistory::class);
    }
}
