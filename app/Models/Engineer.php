<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Engineer extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'name_kana',
        'email',
        'phone',
        'affiliation',
        'affiliation_contact',
        'affiliation_email',
        'age',
        'gender',
        'nationality',
        'nearest_station',
        'affiliation_type',
    ];

    public function profile(): HasOne
    {
        return $this->hasOne(EngineerProfile::class);
    }

    public function engineerSkills(): HasMany
    {
        return $this->hasMany(EngineerSkill::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'engineer_skills')
            ->withPivot(['experience_years', 'proficiency_level'])
            ->withTimestamps();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function matchingScores(): HasMany
    {
        return $this->hasMany(MatchingScore::class);
    }
}
