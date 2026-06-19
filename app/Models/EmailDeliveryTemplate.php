<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * 配信テンプレライブラリ（テナント共有）。
 * 目的別(purpose)に複数の件名+本文テンプレを持てる。
 * 既存 EmailBodyTemplate（1ユーザー1署名プロファイル）とは別概念。
 */
class EmailDeliveryTemplate extends Model
{
    use BelongsToTenant;

    /** 配信目的の語彙。delivery_campaigns.delivery_purpose と同期。 */
    public const PURPOSES = ['standard', 'real_spot', 'existing_customer'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'purpose',
        'name',
        'subject',
        'body_text',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
