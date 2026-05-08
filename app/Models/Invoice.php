<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice Model（Phase C）
 *
 * 請求書本体。案件×月で1請求書。
 * 顧客名/住所/技術者名/請求元情報は発行時点のスナップショット。
 */
class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'customer_id',
        'year_month',
        'invoice_number',
        'order_number',
        'quote_number',
        'subject_name',
        'work_period_text',
        'work_location',
        'delivery_items_text',
        'transportation_note_text',
        'delivery_date_text',
        'delivery_place_text',
        'payment_terms_text',
        'issued_date',
        'due_date',
        'subtotal',
        'tax',
        'total',
        'status',
        'approved',
        'approved_at',
        'approved_by',
        'pdf_path',
        'notes',
        'customer_name_snapshot',
        'customer_address_snapshot',
        'engineer_name_snapshot',
        'issuer_name_snapshot',
        'issuer_postal_code_snapshot',
        'issuer_address_snapshot',
        'issuer_tel_snapshot',
        'issuer_fax_snapshot',
        'issuer_logo_snapshot',
        'issuer_seal_snapshot',
        'issuer_invoice_number_snapshot',
        'issuer_bank_snapshot',
        'settlement_unit_minutes_snapshot',
        'client_deduction_hours_snapshot',
        'client_overtime_hours_snapshot',
        'client_deduction_unit_price_snapshot',
        'client_overtime_unit_price_snapshot',
    ];

    protected $casts = [
        'issued_date' => 'date:Y-m-d',
        'due_date'    => 'date:Y-m-d',
        'subtotal'    => 'decimal:2',
        'tax'         => 'decimal:2',
        'total'       => 'decimal:2',
        'approved'    => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    /**
     * 明細から金額を再計算してプロパティに反映する（保存はしない）。
     *  - 経費(is_expense=true) は 小計には入れず、税対象外として 合計に直接加算
     *  - tax_rate ごとに集計してから合算するため、丸め誤差を最小化
     */
    public function recalcAmounts(): void
    {
        $byRate    = []; // tax_rate => subtotal（課税対象のみ）
        $expense   = 0.0;

        foreach ($this->lines as $line) {
            if ($line->is_expense) {
                $expense += (float) $line->amount;
                continue;
            }
            $rate = (string) $line->tax_rate;
            $byRate[$rate] = ($byRate[$rate] ?? 0) + (float) $line->amount;
        }

        $subtotal = 0.0;
        $tax      = 0.0;
        foreach ($byRate as $rate => $sub) {
            $subtotal += $sub;
            $tax      += round($sub * (float) $rate);
        }

        $this->subtotal = round($subtotal, 2);
        $this->tax      = round($tax, 2);
        $this->total    = round($subtotal + $tax + $expense, 2);
    }

    /** 経費(is_expense=true) の合計（税対象外） */
    public function getExpenseTotalAttribute(): float
    {
        return (float) $this->lines->where('is_expense', true)->sum('amount');
    }
}
