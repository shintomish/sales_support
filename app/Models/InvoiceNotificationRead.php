<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceNotificationRead extends Model
{
    protected $fillable = [
        'tenant_id', 'invoice_id', 'user_id', 'notification_type', 'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
