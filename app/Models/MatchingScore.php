<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchingScore extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'engineer_id',
        'score',
        'skill_match_score',
        'price_match_score',
        'location_match_score',
        'availability_match_score',
        'calculated_at',
    ];

    protected $casts = [
        'score'                    => 'decimal:2',
        'skill_match_score'        => 'decimal:2',
        'price_match_score'        => 'decimal:2',
        'location_match_score'     => 'decimal:2',
        'availability_match_score' => 'decimal:2',
        'calculated_at'            => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(PublicProject::class, 'project_id');
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }
}
