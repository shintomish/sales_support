<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Tenant;
use App\Models\WorkRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 請求書発行サービス（Phase C）
 *
 * 案件×月の試算結果（BillingCalculationService）から明細を自動生成して
 * draft 状態の Invoice を作成する。
 */
class InvoiceCreationService
{
    public function __construct(
        private readonly InvoiceNumberService $numberService,
        private readonly BillingCalculationService $calculator,
        private readonly InvoiceDueDateCalculator $dueDateCalculator,
    ) {}

    /**
     * @param array{
     *   issued_date?: string|null,
     *   due_date?: string|null,
     *   notes?: string|null,
     *   order_number?: string|null,
     *   vendor_metadata?: array|null,
     *   language?: string|null,
     * } $options
     */
    public function createFromDeal(Deal $deal, string $yearMonth, array $options = []): Invoice
    {
        $customer = $deal->customer;
        if (!$customer) {
            throw new RuntimeException('案件に取引先が紐付いていません');
        }

        $contract = $deal->sesContract;
        $record   = WorkRecord::where('deal_id', $deal->id)
            ->where('year_month', $yearMonth)
            ->first();

        $calc = $this->calculator->calculate($contract, $record);

        // 請求日のデフォルトは対象年月の月末（業務慣習）
        [$y, $m] = explode('-', $yearMonth);
        $defaultIssuedDate = Carbon::create((int) $y, (int) $m, 1)->endOfMonth()->toDateString();
        $issuedDate = $options['issued_date'] ?? $defaultIssuedDate;
        $dueDate    = $options['due_date'] ?? $this->dueDateCalculator->calculate($yearMonth, $contract?->payment_site);

        $tenant = Tenant::query()->find($deal->tenant_id);

        $periodStart = Carbon::create((int) $y, (int) $m, 1);
        $periodEnd   = $periodStart->copy()->endOfMonth();
        $workPeriod  = sprintf(
            '%d年%d月%d日～%d年%d月%d日',
            $periodStart->year, $periodStart->month, $periodStart->day,
            $periodEnd->year,   $periodEnd->month,   $periodEnd->day,
        );
        $paymentTerms = $this->paymentTermsText($contract?->payment_site);

        return DB::transaction(function () use ($deal, $customer, $contract, $record, $calc, $yearMonth, $issuedDate, $dueDate, $tenant, $options, $workPeriod, $paymentTerms) {
            $number = $this->numberService->generate($customer, $yearMonth);

            $invoice = new Invoice([
                'tenant_id'                       => $deal->tenant_id,
                'deal_id'                         => $deal->id,
                'customer_id'                     => $customer->id,
                'year_month'                      => $yearMonth,
                'invoice_number'                  => $number,
                'order_number'                    => $options['order_number'] ?? $contract?->order_number,
                'vendor_metadata'                 => $options['vendor_metadata'] ?? null,
                'language'                        => $options['language'] ?? 'ja',
                'subject_name'                    => $deal->title,
                'work_period_text'                => $workPeriod,
                'work_location'                   => null,
                'delivery_items_text'             => '作業報告書',
                'transportation_note_text'        => 'お客様指示の基、移動が発生した場合は別途実費にてご請求',
                'delivery_date_text'              => '御社ご指定日',
                'delivery_place_text'             => '御社ご指定場所',
                'payment_terms_text'              => $paymentTerms,
                'issued_date'                     => $issuedDate,
                'due_date'                        => $dueDate,
                'status'                          => 'draft',
                'notes'                           => $options['notes'] ?? null,
                'customer_name_snapshot'          => (($options['language'] ?? 'ja') === 'en' && !empty($customer->company_name_en))
                    ? $customer->company_name_en
                    : $customer->company_name,
                'customer_address_snapshot'       => $customer->address,
                'engineer_name_snapshot'          => $contract?->engineer_name,
                'issuer_name_snapshot'            => $tenant?->invoice_issuer_name,
                'issuer_postal_code_snapshot'     => $tenant?->invoice_issuer_postal_code,
                'issuer_address_snapshot'         => $tenant?->invoice_issuer_address,
                'issuer_tel_snapshot'             => $tenant?->invoice_issuer_tel,
                'issuer_fax_snapshot'             => $tenant?->invoice_issuer_fax,
                'issuer_logo_snapshot'            => $tenant?->invoice_issuer_logo_path,
                'issuer_round_seal_snapshot'      => $tenant?->invoice_issuer_round_seal_path,
                'issuer_square_seal_snapshot'     => $tenant?->invoice_issuer_square_seal_path,
                'issuer_url_snapshot'             => $tenant?->invoice_issuer_url,
                'issuer_invoice_number_snapshot'  => $tenant?->invoice_issuer_invoice_number,
                'issuer_bank_snapshot'            => $this->formatBankInfo($tenant),
                'settlement_unit_minutes_snapshot'     => $contract?->settlement_unit_minutes,
                'client_deduction_hours_snapshot'      => $contract?->client_deduction_hours,
                'client_overtime_hours_snapshot'       => $contract?->client_overtime_hours,
                'client_deduction_unit_price_snapshot' => $contract?->client_deduction_unit_price,
                'client_overtime_unit_price_snapshot'  => $contract?->client_overtime_unit_price,
            ]);
            $invoice->save();

            $sort = 0;
            foreach ($this->buildLines($deal, $record, $calc) as $line) {
                $line['invoice_id'] = $invoice->id;
                $line['sort_order'] = $sort++;
                $line['amount']     = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $line['is_expense'] = $line['is_expense'] ?? false;
                InvoiceLine::query()->create($line);
            }

            $invoice->load('lines');
            $invoice->recalcAmounts();
            $invoice->save();

            return $invoice;
        });
    }

    /**
     * 試算結果から明細行を組み立てる
     *
     * 新仕様（INV_Aizen 2026-05-05）:
     *  - 基本額の摘要は「{金額}円【基本月額】」表記（ses_contracts.income_amount 由来）
     *  - 件名/作業期間/作業場所/支払条件はメタ情報として invoices テーブルに保持し、
     *    PDF/編集画面で表示する。明細行（金額計上）は基本額・控除・超過・交通費のみ。
     *
     * @return array<int, array<string,mixed>>
     */
    private function buildLines(Deal $deal, ?WorkRecord $record, array $calc): array
    {
        $lines = [];

        if ($calc['basic'] > 0) {
            $lines[] = [
                'description' => sprintf('%s円 【基本月額】', number_format((float) $calc['basic'])),
                'quantity'    => 1,
                'unit'        => null,
                'unit_price'  => $calc['basic'],
                'tax_rate'    => 0.10,
            ];
        }
        if ($calc['deduction'] > 0) {
            // 控除: 数量=不足時間, 単価=-控除単価, 金額=qty*price (負の値)
            $lines[] = [
                'description' => '控除（精算下限未達）',
                'quantity'    => $calc['deduction_hours'],
                'unit'        => 'h',
                'unit_price'  => -1 * $calc['deduction_unit'],
                'tax_rate'    => 0.10,
            ];
        }
        if ($calc['overtime'] > 0) {
            // 超過: 数量=超過時間, 単価=超過単価, 金額=qty*price
            $lines[] = [
                'description' => '超過（精算上限超）',
                'quantity'    => $calc['overtime_hours'],
                'unit'        => 'h',
                'unit_price'  => $calc['overtime_unit'],
                'tax_rate'    => 0.10,
            ];
        }
        if ($calc['transportation'] > 0) {
            // 業務交通費は実費精算（経費・非課税）
            $lines[] = [
                'description' => '業務交通費',
                'quantity'    => 1,
                'unit'        => null,
                'unit_price'  => $calc['transportation'],
                'tax_rate'    => 0.0,
                'is_expense'  => true,
            ];
        }

        if (empty($lines)) {
            // 何も計上できない場合は基本額0の1行を入れる（編集前提）
            $lines[] = [
                'description' => '0円 【基本月額】',
                'quantity'    => 1,
                'unit'        => null,
                'unit_price'  => 0,
                'tax_rate'    => 0.10,
            ];
        }

        return $lines;
    }

    /**
     * payment_site から支払条件文言を組み立てる。
     *
     * 翌月末を起点に offset(=site-30) 日加算した到達点を
     * 「翌(々*)月{N}日」形式に分解する（業界慣習に合わせ 30 日 = 1 ヶ月扱い）。
     *
     *   site=30 → 翌月末日
     *   site=45 → 翌々月15日
     *   site=50 → 翌々月20日
     *   site=55 → 翌々月25日
     *   site=60 → 翌々月末日
     *   site=70 → 翌々々月10日
     *   site=90 → 翌々々月末日
     */
    private function paymentTermsText(?int $paymentSite): string
    {
        $site = (int) ($paymentSite ?? 30);
        $offset = $site - 30;

        $monthIndex = 1; // 1=翌月, 2=翌々月, 3=翌々々月, ...
        $day = 30;       // 翌月末日（30日扱い）

        if ($offset > 0) {
            $monthIndex += intdiv($offset, 30);
            $day = $offset % 30;
            if ($day === 0) {
                $day = 30; // 月末扱い
            } else {
                $monthIndex++;
            }
        }

        $monthLabel = match ($monthIndex) {
            1 => '翌月',
            2 => '翌々月',
            3 => '翌々々月',
            default => sprintf('翌+%dヶ月', $monthIndex - 1),
        };

        $dayLabel = $day === 30 ? '末日' : sprintf('%d日', $day);

        return sprintf('月末締め%s%s現金お支払', $monthLabel, $dayLabel);
    }

    private function formatBankInfo(?Tenant $tenant): ?string
    {
        if (!$tenant) return null;
        $parts = array_filter([
            $tenant->invoice_issuer_bank_name,
            $tenant->invoice_issuer_bank_branch,
            $tenant->invoice_issuer_bank_account_type,
            $tenant->invoice_issuer_bank_account_number,
            $tenant->invoice_issuer_bank_account_holder,
        ]);
        return empty($parts) ? null : implode(' / ', $parts);
    }
}
