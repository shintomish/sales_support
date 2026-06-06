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
        'unsubscribe_token',
        'unsubscribe_reason',
        'unsubscribed_at',
        'soft_bounce_count',
        'last_bounce_at',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->unsubscribe_token)) {
                $model->unsubscribe_token = \Illuminate\Support\Str::uuid()->toString();
            }
        });
    }

    protected $casts = [
        'is_active'         => 'boolean',
        'unsubscribed_at'   => 'datetime',
        'soft_bounce_count' => 'integer',
        'last_bounce_at'    => 'datetime',
    ];

    public function sendHistories(): HasMany
    {
        return $this->hasMany(DeliverySendHistory::class);
    }
}
