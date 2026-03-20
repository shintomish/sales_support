<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;

class Email extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'gmail_message_id',
        'thread_id',
        'subject',
        'from_address',
        'from_name',
        'to_address',
        'body_text',
        'body_html',
        'received_at',
        'is_read',
        'contact_id',
        'deal_id',
        'customer_id',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'is_read'     => 'boolean',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
