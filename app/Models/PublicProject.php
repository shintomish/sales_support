<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PublicProject extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'posted_by_customer_id',
        'posted_by_user_id',
        'project_mail_source_id',
        'title',
        'description',
        'end_client',
        'unit_price_min',
        'unit_price_max',
        'contract_type',
        'contract_period_months',
        'start_date',
        'deduction_hours',
        'overtime_hours',
        'settlement_unit_minutes',
        'work_location',
        'nearest_station',
        'work_style',
        'remote_frequency',
        'required_experience_years',
        'team_size',
        'interview_count',
        'headcount',
        'status',
        'views_count',
        'applications_count',
        'published_at',
        'expires_at',
    ];

    protected $casts = [
        'unit_price_min'           => 'decimal:2',
        'unit_price_max'           => 'decimal:2',
        'start_date'               => 'date:Y-m-d',
        'deduction_hours'          => 'decimal:2',
        'overtime_hours'           => 'decimal:2',
        'published_at'             => 'datetime',
        'expires_at'               => 'datetime',
    ];

    public function projectMailSource(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProjectMailSource::class);
    }

    public function postedByCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'posted_by_customer_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function requiredSkills(): HasMany
    {
        return $this->hasMany(ProjectRequiredSkill::class, 'project_id');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'project_required_skills', 'project_id', 'skill_id')
            ->withPivot(['is_required', 'min_experience_years'])
            ->withTimestamps();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'project_id');
    }

    public function matchingScores(): HasMany
    {
        return $this->hasMany(MatchingScore::class, 'project_id');
    }

    public function favoriteByUsers(): HasMany
    {
        return $this->hasMany(FavoriteProject::class, 'project_id');
    }

    // ── スコープ ──────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
