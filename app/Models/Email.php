<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Traits\BelongsToTenant;

class Email extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * is_read 変動時に未読カウントキャッシュを自動 invalidate。
     *
     * - 新規作成 (is_read=false で取込): unread+1 反映が必要
     * - is_read を true/false に切替: 単一メール既読化や status 反転で反映
     * - 削除: 未読メール削除なら unread-1 反映が必要
     *
     * 注: Query Builder の一括 update (例: RescoreJobRunner) は model 経由ではないため
     * 別途明示的に Cache::forget を呼ぶ必要がある (既に対応済)。
     */
    protected static function booted(): void
    {
        static::saved(function (Email $email) {
            if ($email->wasRecentlyCreated || $email->wasChanged('is_read')) {
                Cache::forget("emails:unread_count:tenant:{$email->tenant_id}");
            }
        });
        static::deleted(function (Email $email) {
            if (!$email->is_read) {
                Cache::forget("emails:unread_count:tenant:{$email->tenant_id}");
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'gmail_message_id',
        'rfc_message_id',
        'thread_id',
        'subject',
        'from_address',
        'from_name',
        'to_address',
        'body_text',
        'body_html',
        'received_at',
        'arrived_at',
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
        'arrived_at'     => 'datetime',
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

    /** このメール（見積依頼）を起点に作成された見積/請求書。 */
    public function sourcedInvoices()
    {
        return $this->hasMany(Invoice::class, 'source_email_id');
    }
}
