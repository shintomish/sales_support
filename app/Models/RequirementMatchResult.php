<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 案件要件 × 候補技術者 の対照表 (docs/480 §3.2)
 *
 * - PMS × EMS マッチ: engineer_mail_source_id にひも付け
 * - PMS × 登録済 Engineer: engineer_id にひも付け
 * (どちらか片方が non-null・migration の CHECK 制約で保証)
 */
class RequirementMatchResult extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'project_mail_source_id',
        'engineer_mail_source_id',
        'engineer_id',
        'requirements_json',
        'matches_json',
        'model',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'generated_at',
        'edited_by',
        'edited_at',
    ];

    protected $casts = [
        'requirements_json' => 'array',
        'matches_json'      => 'array',
        'generated_at'      => 'datetime',
        'edited_at'         => 'datetime',
    ];

    public function projectMailSource(): BelongsTo
    {
        return $this->belongsTo(ProjectMailSource::class);
    }

    public function engineerMailSource(): BelongsTo
    {
        return $this->belongsTo(EngineerMailSource::class);
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
