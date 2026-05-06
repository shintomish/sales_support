<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 社内バグ・要望フィードバック（feedback_reports）
 *
 * /settings/feedback から投稿、shintomi にメール通知 + DB 保存。
 * super_admin はテナント横断で閲覧・status 更新する用途のため、
 * 横断閲覧時は withoutGlobalScope(TenantScope::class) を使う。
 */
class FeedbackReport extends Model
{
    use BelongsToTenant;

    public const TYPE_BUG     = 'bug';
    public const TYPE_REQUEST = 'request';
    public const TYPE_OTHER   = 'other';

    public const STATUS_NEW    = 'new';
    public const STATUS_SEEN   = 'seen';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'subject',
        'body',
        'url',
        'user_agent',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
