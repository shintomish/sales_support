<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAttachment extends Model
{
    protected $fillable = [
        'email_id',
        'filename',
        'mime_type',
        'size',
        'gmail_attachment_id',
        'storage_path',
    ];

    public function email()
    {
        return $this->belongsTo(Email::class);
    }
}
