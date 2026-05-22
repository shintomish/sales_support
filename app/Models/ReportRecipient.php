<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * 自動配信レポートの宛先（report_recipients）
 *
 * `report_type` で daily_delivery_report / weekly / alert 等を切り分けて購読管理。
 */
class ReportRecipient extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'email',
        'name',
        'report_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
