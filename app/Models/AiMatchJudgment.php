<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * /mail-search の AIマッチ判定キャッシュ。
 *   (tenant, query_hash, target_type, target_id) で一意。verdict: ◯ / △ / ×。
 */
class AiMatchJudgment extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'query_hash', 'target_type', 'target_id', 'verdict', 'reason'];

    public const TYPES = ['project_mail', 'public_project', 'engineer_mail', 'engineer'];
}
