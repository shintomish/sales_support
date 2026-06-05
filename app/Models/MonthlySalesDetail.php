<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MonthlySalesDetail Model
 *
 * 月別売上集計の明細 (docs/460)。1 行 = (tenant, year, month, ses_contract)。
 * 契約期間ベース・月単位粗計上で MonthlySalesAggregationService が生成する。
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property int         $ses_contract_id
 * @property int         $year
 * @property int         $month
 * @property string|null $category
 * @property float       $revenue
 * @property float       $cost
 * @property float       $profit
 * @property string      $computed_at
 */
class MonthlySalesDetail extends Model
{
    use BelongsToTenant;

    protected $table = 'monthly_sales_details';

    protected $fillable = [
        'tenant_id',
        'ses_contract_id',
        'year',
        'month',
        'category',
        'revenue',
        'cost',
        'profit',
        'computed_at',
    ];

    protected $casts = [
        'year'        => 'integer',
        'month'       => 'integer',
        'revenue'     => 'decimal:2',
        'cost'        => 'decimal:2',
        'profit'      => 'decimal:2',
        'computed_at' => 'datetime',
    ];

    public function sesContract(): BelongsTo
    {
        return $this->belongsTo(SesContract::class);
    }
}
