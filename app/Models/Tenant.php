<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'plan', 'is_active', 'ses_enabled', 'feature_requirement_matching',
        'fiscal_year_end_month', 'first_period_fiscal_year',
        'invoice_issuer_name',
        'invoice_issuer_postal_code',
        'invoice_issuer_address',
        'invoice_issuer_tel',
        'invoice_issuer_fax',
        'invoice_issuer_logo_path',
        'invoice_issuer_round_seal_path',
        'invoice_issuer_square_seal_path',
        'invoice_issuer_url',
        'invoice_issuer_invoice_number',
        'invoice_issuer_name_en',
        'invoice_issuer_address_en',
        'invoice_issuer_email',
        'invoice_issuer_bank_details_en',
        'invoice_issuer_bank_account_holder_en',
        'invoice_email_subject_template',
        'invoice_email_body_template',
        'estimate_email_subject_template',
        'estimate_email_body_template',
        'purchase_order_email_subject_template',
        'purchase_order_email_body_template',
        'invoice_issuer_bank_name',
        'invoice_issuer_bank_branch',
        'invoice_issuer_bank_account_type',
        'invoice_issuer_bank_account_number',
        'invoice_issuer_bank_account_holder',
    ];

    protected $casts = [
        'ses_enabled'                  => 'boolean',
        'feature_requirement_matching' => 'boolean',
        'fiscal_year_end_month'        => 'integer',
        'first_period_fiscal_year'     => 'integer',
    ];

    // ── 会計年度ヘルパー (docs/460) ────────────────────────────
    // 「年度」= 決算月で終わる会計年度を、その終了月の暦年で表す
    // (9月決算なら 2025-10〜2026-09 = 2026年度)。決算月未設定なら暦年(12月)扱い。

    /** 決算月 (1-12)。未設定なら 12 (暦年) を返す */
    public function fiscalEndMonth(): int
    {
        $m = $this->fiscal_year_end_month;
        return ($m >= 1 && $m <= 12) ? (int) $m : 12;
    }

    /** 指定日 (既定=今日) が属する年度を返す */
    public function currentFiscalYear(?\Carbon\Carbon $date = null): int
    {
        $date = $date ?? \Carbon\Carbon::now();
        $end  = $this->fiscalEndMonth();
        // 終了月までは当年が年度、超えたら翌年が年度
        return $date->month <= $end ? (int) $date->year : (int) $date->year + 1;
    }

    /** 年度に属する 12 ヶ月を時系列 [['year'=>..,'month'=>..], ...] で返す */
    public function fiscalYearMonths(int $fiscalYear): array
    {
        $end        = $this->fiscalEndMonth();
        $startMonth = ($end % 12) + 1;             // 決算月の翌月
        $startYear  = $end === 12 ? $fiscalYear : $fiscalYear - 1;

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $startMonth + $i;
            $y = $startYear + intdiv($m - 1, 12);
            $months[] = ['year' => $y, 'month' => (($m - 1) % 12) + 1];
        }
        return $months;
    }

    /** 年度に対応する「期」。第1期年度が未設定なら null */
    public function periodFor(int $fiscalYear): ?int
    {
        $base = $this->first_period_fiscal_year;
        return $base ? $fiscalYear - (int) $base + 1 : null;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
