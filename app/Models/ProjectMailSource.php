<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMailSource extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'email_id',
        'score',
        'score_reasons',
        'engine',
        'customer_name',
        'sales_contact',
        'phone',
        'title',
        'required_skills',
        'preferred_skills',
        'process',
        'work_location',
        'remote_ok',
        'unit_price_min',
        'unit_price_max',
        'age_limit',
        'nationality_ok',
        'contract_type',
        'start_date',
        'supply_chain',
        'status',
        'lost_reason',
        'received_at',
    ];

    protected $casts = [
        'score_reasons'    => 'array',
        'required_skills'  => 'array',
        'preferred_skills' => 'array',
        'process'          => 'array',
        'remote_ok'        => 'boolean',
        'nationality_ok'   => 'boolean',
        'unit_price_min'   => 'decimal:2',
        'unit_price_max'   => 'decimal:2',
        'received_at'      => 'datetime',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
