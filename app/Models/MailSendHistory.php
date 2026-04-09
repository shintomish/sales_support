<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailSendHistory extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_mail_id',
        'send_type',
        'to_address',
        'to_name',
        'subject',
        'body',
        'status',
        'error_message',
        'sent_by',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function projectMail(): BelongsTo
    {
        return $this->belongsTo(ProjectMailSource::class, 'project_mail_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
