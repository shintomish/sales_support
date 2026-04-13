<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Engineer;
use App\Models\PublicProject;

class DeliverySendHistory extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'campaign_id',
        'delivery_address_id',
        'engineer_id',
        'public_project_id',
        'email',
        'name',
        'status',
        'ses_message_id',
        'error_message',
        'replied_at',
        'reply_email_id',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DeliveryCampaign::class, 'campaign_id');
    }

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(DeliveryAddress::class, 'delivery_address_id');
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class, 'engineer_id');
    }

    public function publicProject(): BelongsTo
    {
        return $this->belongsTo(PublicProject::class, 'public_project_id');
    }

    public function replyEmail(): BelongsTo
    {
        return $this->belongsTo(Email::class, 'reply_email_id');
    }
}
