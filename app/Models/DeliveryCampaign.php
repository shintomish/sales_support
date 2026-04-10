<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryCampaign extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_mail_id',
        'user_id',
        'subject',
        'body',
        'total_count',
        'success_count',
        'failed_count',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function projectMailSource(): BelongsTo
    {
        return $this->belongsTo(ProjectMailSource::class, 'project_mail_id');
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
