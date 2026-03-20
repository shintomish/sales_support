<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailToken extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'gmail_address',
        'access_token',
        'refresh_token',
        'token_expires_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];
}
