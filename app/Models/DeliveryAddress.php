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
        'is_active' => 'boolean',
    ];

    public function sendHistories(): HasMany
    {
        return $this->hasMany(DeliverySendHistory::class);
    }
}
