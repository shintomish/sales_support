<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineerMailSource extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'email_id',
        'score',
        'score_reasons',
        'engine',
        'name',
        'age',
        'unit_price_min',
        'unit_price_max',
        'affiliation_type',
        'available_from',
        'nearest_station',
        'skills',
        'has_attachment',
        'status',
        'received_at',
    ];

    protected $casts = [
        'score_reasons'  => 'array',
        'skills'         => 'array',
        'has_attachment' => 'boolean',
        'received_at'    => 'datetime',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
