<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SesContract Model
 *
 * SES案件の精算条件・金額・契約期間を管理する。
 * deals テーブルと 1:1 で紐付く。
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property int         $deal_id
 * @property float|null  $income_amount
 * @property float|null  $billing_plus_22
 * @property float|null  $billing_plus_29
 * @property string|null $sales_support_payee
 * @property float|null  $sales_support_fee
 * @property float|null  $adjustment_amount
 * @property float|null  $profit
 * @property float|null  $profit_rate_29
 * @property float|null  $client_deduction_unit_price
 * @property float|null  $client_deduction_hours
 * @property float|null  $client_overtime_unit_price
 * @property float|null  $client_overtime_hours
 * @property int|null    $settlement_unit_minutes
 * @property int|null    $payment_site
 * @property float|null  $vendor_deduction_unit_price
 * @property float|null  $vendor_deduction_hours
 * @property float|null  $vendor_overtime_unit_price
 * @property float|null  $vendor_overtime_hours
 * @property int|null    $vendor_payment_site
 * @property string|null $contract_start
 * @property string|null $contract_period_start
 * @property string|null $contract_period_end
 * @property string|null $affiliation_period_end
 */
class SesContract extends Model
{
    use BelongsToTenant;

    protected $table = 'ses_contracts';

    protected $fillable = [
        'tenant_id',
        'deal_id',
        // 金額系
        'income_amount',
        'billing_plus_22',
        'billing_plus_29',
        'sales_support_payee',
        'sales_support_fee',
        'adjustment_amount',
        'profit',
        'profit_rate_29',
        // 顧客側精算条件
        'client_deduction_unit_price',
        'client_deduction_hours',
        'client_overtime_unit_price',
        'client_overtime_hours',
        'settlement_unit_minutes',
        'payment_site',
        // 仕入れ側精算条件
        'vendor_deduction_unit_price',
        'vendor_deduction_hours',
        'vendor_overtime_unit_price',
        'vendor_overtime_hours',
        'vendor_payment_site',
        // 契約期間
        'contract_start',
        'contract_period_start',
        'contract_period_end',
        'affiliation_period_end',
    ];

    protected $casts = [
        'income_amount'                => 'decimal:2',
        'billing_plus_22'              => 'decimal:2',
        'billing_plus_29'              => 'decimal:2',
        'sales_support_fee'            => 'decimal:2',
        'adjustment_amount'            => 'decimal:2',
        'profit'                       => 'decimal:2',
        'profit_rate_29'               => 'decimal:2',
        'client_deduction_unit_price'  => 'decimal:2',
        'client_deduction_hours'       => 'decimal:2',
        'client_overtime_unit_price'   => 'decimal:2',
        'client_overtime_hours'        => 'decimal:2',
        'settlement_unit_minutes'      => 'integer',
        'payment_site'                 => 'integer',
        'vendor_deduction_unit_price'  => 'decimal:2',
        'vendor_deduction_hours'       => 'decimal:2',
        'vendor_overtime_unit_price'   => 'decimal:2',
        'vendor_overtime_hours'        => 'decimal:2',
        'vendor_payment_site'          => 'integer',
        'contract_start'               => 'date:Y-m-d',
        'contract_period_start'        => 'date:Y-m-d',
        'contract_period_end'          => 'date:Y-m-d',
    ];

    // ── リレーション ──────────────────────────────────

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    // ── スコープ ──────────────────────────────────────

    /**
     * 契約期間終了が N日以内に迫っている案件を絞り込む
     * 例: SesContract::expiringWithin(30)->get()
     */
    public function scopeExpiringWithin($query, int $days = 30)
    {
        return $query
            ->whereNotNull('contract_period_end')
            ->whereBetween('contract_period_end', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    /**
     * 契約期間終了が過去の案件（期限切れ）
     */
    public function scopeExpired($query)
    {
        return $query
            ->whereNotNull('contract_period_end')
            ->where('contract_period_end', '<', now()->toDateString());
    }

    // ── アクセサ ──────────────────────────────────────

    /**
     * 利益率（%）を返す
     * income_amount が 0 または null の場合は null を返す
     */
    public function getProfitRateAttribute(): ?float
    {
        if (empty($this->income_amount) || $this->income_amount == 0) {
            return null;
        }
        return round(($this->profit / $this->income_amount) * 100, 1);
    }

    /**
     * 契約終了まで残り何日か返す（過去の場合は負の値）
     */
    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (!$this->contract_period_end) {
            return null;
        }
        return (int) now()->diffInDays($this->contract_period_end, false);
    }
}
