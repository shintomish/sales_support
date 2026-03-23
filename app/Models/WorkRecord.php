<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WorkRecord Model
 *
 * SES案件の月次勤怠・請求情報を管理する。
 * deal_id + year_month でユニーク（月次スナップショット）。
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property int         $deal_id
 * @property string      $year_month          YYYY-MM 形式
 * @property string|null $timesheet_received_date
 * @property float|null  $transportation_fee
 * @property float|null  $absence_days
 * @property float|null  $paid_leave_days
 * @property bool|null   $invoice_exists
 * @property string|null $invoice_received_date
 * @property string|null $notes
 */
class WorkRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'work_records';

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'year_month',
        'timesheet_received_date',
        'transportation_fee',
        'absence_days',
        'paid_leave_days',
        'invoice_exists',
        'invoice_received_date',
        'notes',
    ];

    protected $casts = [
        'timesheet_received_date' => 'date:Y-m-d',
        'transportation_fee'      => 'decimal:2',
        'absence_days'            => 'decimal:1',
        'paid_leave_days'         => 'decimal:1',
        'invoice_exists'          => 'boolean',
        'invoice_received_date'   => 'date:Y-m-d',
    ];

    // ── リレーション ──────────────────────────────────

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    // ── スコープ ──────────────────────────────────────

    /**
     * 指定年月のレコードを絞り込む
     * 例: WorkRecord::ofMonth('2026-03')->get()
     */
    public function scopeOfMonth($query, string $yearMonth)
    {
        return $query->where('year_month', $yearMonth);
    }

    /**
     * 勤務表が未受領のレコードを絞り込む
     */
    public function scopeTimesheetPending($query)
    {
        return $query->whereNull('timesheet_received_date');
    }

    /**
     * 請求書が未受領（invoice_exists=true かつ received_date が null）のレコード
     */
    public function scopeInvoicePending($query)
    {
        return $query
            ->where('invoice_exists', true)
            ->whereNull('invoice_received_date');
    }
}
