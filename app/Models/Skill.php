<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    protected $fillable = ['name', 'category'];

    public function engineerSkills(): HasMany
    {
        return $this->hasMany(EngineerSkill::class);
    }

    public function projectRequiredSkills(): HasMany
    {
        return $this->hasMany(ProjectRequiredSkill::class);
    }
}
