<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'engineer_id',
        'applied_by_user_id',
        'message',
        'resume_file_path',
        'proposed_unit_price',
        'status',
        'interview_date',
        'interview_memo',
        'deal_id',
        'commission_rate',
        'commission_amount',
        'reviewed_at',
    ];

    protected $casts = [
        'proposed_unit_price' => 'decimal:2',
        'commission_rate'     => 'decimal:2',
        'commission_amount'   => 'decimal:2',
        'interview_date'      => 'datetime',
        'reviewed_at'         => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(PublicProject::class, 'project_id');
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }

    public function appliedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }
}
