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
        'delivery_date_text',
        'delivery_place_text',
        'payment_terms_text',
        'issued_date',
        'due_date',
        'subtotal',
        'tax',
        'total',
        'status',
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
        'issuer_invoice_number_snapshot',
        'issuer_bank_snapshot',
    ];

    protected $casts = [
        'issued_date' => 'date:Y-m-d',
        'due_date'    => 'date:Y-m-d',
        'subtotal'    => 'decimal:2',
        'tax'         => 'decimal:2',
        'total'       => 'decimal:2',
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
     * tax_rate ごとに集計してから合算するため、丸め誤差を最小化。
     */
    public function recalcAmounts(): void
    {
        $byRate = []; // tax_rate => subtotal
        foreach ($this->lines as $line) {
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
        $this->total    = round($subtotal + $tax, 2);
    }
}
