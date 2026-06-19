<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\EngineerMailSource;

class DeliveryCampaign extends Model
{
    use BelongsToTenant, HasFactory;

    // ── 提案スレッド系 send_type 集合（4箇所同期の単一情報源）──────────────────
    // CLAUDE.md「確定済み設計判断」より、提案スレッド系の whereIn を増減する時は
    // index(exclude_proposals) / proposalThreads(本体+campaignsByThread) /
    // ProjectMailController::thread / EngineerMailController::thread を必ず同期する。
    // それらを以下の定数から派生させ、ドリフトを構造的に防ぐ（回帰: DeliveryCampaignSendTypeSyncTest）。

    /** 案件メール由来の提案 send_type（ProjectMailController::thread） */
    public const PROJECT_PROPOSAL_TYPES = ['proposal', 'matching_proposal', 'bulk'];

    /** 技術者メール由来の提案 send_type（EngineerMailController::thread） */
    public const ENGINEER_PROPOSAL_TYPES = ['engineer_proposal', 'engineer_proposal_bulk'];

    /** 提案スレッド系の全 send_type（proposalThreads 本体 + campaignsByThread）= 案件 ∪ 技術者 */
    public const PROPOSAL_THREAD_TYPES = [...self::PROJECT_PROPOSAL_TYPES, ...self::ENGINEER_PROPOSAL_TYPES];

    /**
     * 一斉配信履歴(index exclude_proposals)から除外する send_type。
     * 提案スレッド系 + self_reply（/emails 個別返信。専用「返信履歴」タブのみで表示）。
     * 'delivery'（一斉配信）は 1対多でスレッド概念に合わないため、いずれにも含めない。
     */
    public const EXCLUDE_FROM_DELIVERY_TYPES = [...self::PROPOSAL_THREAD_TYPES, 'self_reply'];

    protected $fillable = [
        'tenant_id',
        'send_type',
        'delivery_type',
        'delivery_purpose',
        'project_mail_id',
        'engineer_mail_source_id',
        'source_email',
        'user_id',
        'subject',
        'body',
        'total_count',
        'success_count',
        'failed_count',
        'sent_at',
        'last_resent_at',
    ];

    protected $casts = [
        'sent_at'        => 'datetime',
        'last_resent_at' => 'datetime',
    ];

    public function projectMailSource(): BelongsTo
    {
        return $this->belongsTo(ProjectMailSource::class, 'project_mail_id');
    }

    public function engineerMailSource(): BelongsTo
    {
        return $this->belongsTo(EngineerMailSource::class, 'engineer_mail_source_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sendHistories(): HasMany
    {
        return $this->hasMany(DeliverySendHistory::class, 'campaign_id');
    }
}
