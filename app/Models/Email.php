<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;

class Email extends Model
{
    use BelongsToTenant, HasFactory;

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
        'category',
        'extracted_data',
        'classified_at',
        'gmail_trashed_at',
        'registered_at',
        'registered_engineer_id',
        'registered_project_id',
        'best_match_score',
        'match_count',
    ];

    protected $casts = [
        'received_at'    => 'datetime',
        'is_read'        => 'boolean',
        'extracted_data' => 'array',
        'classified_at'   => 'datetime',
        'gmail_trashed_at'=> 'datetime',
        'registered_at'   => 'datetime',
    ];

    public function projectMailSource()
    {
        return $this->hasOne(ProjectMailSource::class);
    }

    public function attachments()
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function registeredEngineer()
    {
        return $this->belongsTo(Engineer::class, 'registered_engineer_id');
    }

    public function registeredProject()
    {
        return $this->belongsTo(PublicProject::class, 'registered_project_id');
    }

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
