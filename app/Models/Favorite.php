<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * 汎用お気に入り（技術者/案件・メール由来/登録を横断）。
 * target_type: project_mail / public_project / engineer_mail / engineer
 */
class Favorite extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'target_type', 'target_id'];

    public const TYPES = ['project_mail', 'public_project', 'engineer_mail', 'engineer'];
}
