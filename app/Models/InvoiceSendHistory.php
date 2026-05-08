<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceSendHistory extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'method',
        'to_emails',
        'cc_emails',
        'subject',
        'body',
        'attachments_meta',
        'status',
        'error_message',
        'sent_at',
        'sent_by',
    ];

    protected $casts = [
        'to_emails'        => 'array',
        'cc_emails'        => 'array',
        'attachments_meta' => 'array',
        'sent_at'          => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
