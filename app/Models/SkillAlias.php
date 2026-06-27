<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * スキル同義語辞書の1行（canonical=正規名 / alias=表記揺れ）。
 * グローバルマスタ（全テナント共通・tenant 非依存）。編集は管理者のみ。
 * 参照は App\Services\SkillDictionary（キャッシュ）経由。書き換え後は forgetCache() 必須。
 */
class SkillAlias extends Model
{
    protected $table = 'skill_aliases';

    protected $fillable = ['canonical', 'alias'];
}
