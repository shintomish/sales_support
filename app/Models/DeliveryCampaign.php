<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\EngineerMailSource;

class DeliveryCampaign extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'send_type',
        'project_mail_id',
        'engineer_mail_source_id',
        'user_id',
        'subject',
        'body',
        'total_count',
        'success_count',
        'failed_count',
        'sent_at',
        'last_resent_at',
    ];

    protected $casts = [
        'sent_at'        => 'datetime',
        'last_resent_at' => 'datetime',
    ];

    public function projectMailSource(): BelongsTo
    {
        return $this->belongsTo(ProjectMailSource::class, 'project_mail_id');
    }

    public function engineerMailSource(): BelongsTo
    {
        return $this->belongsTo(EngineerMailSource::class, 'engineer_mail_source_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sendHistories(): HasMany
    {
        return $this->hasMany(DeliverySendHistory::class, 'campaign_id');
    }
}
