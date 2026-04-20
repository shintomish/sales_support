<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineerProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'engineer_id',
        'desired_unit_price_min',
        'desired_unit_price_max',
        'available_from',
        'availability_status',
        'current_project',
        'current_customer',
        'past_client_count',
        'work_style',
        'preferred_location',
        'self_introduction',
        'resume_file_path',
        'github_url',
        'portfolio_url',
        'is_public',
    ];

    protected $casts = [
        'desired_unit_price_min' => 'decimal:2',
        'desired_unit_price_max' => 'decimal:2',
        'available_from'         => 'date:Y-m-d',
        'is_public'              => 'boolean',
    ];

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }
}
