<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectRequiredSkill extends Model
{
    protected $fillable = [
        'project_id',
        'skill_id',
        'is_required',
        'min_experience_years',
    ];

    protected $casts = [
        'is_required'          => 'boolean',
        'min_experience_years' => 'decimal:1',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(PublicProject::class, 'project_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
