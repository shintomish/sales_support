<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineerSkill extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'engineer_id',
        'skill_id',
        'experience_years',
        'proficiency_level',
    ];

    protected $casts = [
        'experience_years'  => 'decimal:1',
        'proficiency_level' => 'integer',
    ];

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
