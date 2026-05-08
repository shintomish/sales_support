<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceLine Model（Phase C）
 *
 * 請求書明細行。複数税率対応。
 *   amount = quantity × unit_price（税抜）
 */
class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'sort_order',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'tax_rate',
        'amount',
        'is_expense',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tax_rate'   => 'decimal:4',
        'amount'     => 'decimal:2',
        'is_expense' => 'boolean',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
