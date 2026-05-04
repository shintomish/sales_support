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
    ) {}

    /**
     * @param array{issued_date?: string|null, due_date?: string|null, notes?: string|null} $options
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

        $issuedDate = $options['issued_date'] ?? Carbon::today()->toDateString();
        $dueDate    = $options['due_date'] ?? $this->calculateDueDate($yearMonth, $contract?->payment_site);

        $tenant = Tenant::query()->find($deal->tenant_id);

        return DB::transaction(function () use ($deal, $customer, $contract, $record, $calc, $yearMonth, $issuedDate, $dueDate, $tenant, $options) {
            $number = $this->numberService->generate($customer, $yearMonth);

            $invoice = new Invoice([
                'tenant_id'                       => $deal->tenant_id,
                'deal_id'                         => $deal->id,
                'customer_id'                     => $customer->id,
                'year_month'                      => $yearMonth,
                'invoice_number'                  => $number,
                'issued_date'                     => $issuedDate,
                'due_date'                        => $dueDate,
                'status'                          => 'draft',
                'notes'                           => $options['notes'] ?? null,
                'customer_name_snapshot'          => $customer->company_name,
                'customer_address_snapshot'       => $customer->address,
                'engineer_name_snapshot'          => $contract?->engineer_name,
                'issuer_name_snapshot'            => $tenant?->invoice_issuer_name,
                'issuer_postal_code_snapshot'     => $tenant?->invoice_issuer_postal_code,
                'issuer_address_snapshot'         => $tenant?->invoice_issuer_address,
                'issuer_tel_snapshot'             => $tenant?->invoice_issuer_tel,
                'issuer_invoice_number_snapshot'  => $tenant?->invoice_issuer_invoice_number,
                'issuer_bank_snapshot'            => $this->formatBankInfo($tenant),
            ]);
            $invoice->save();

            $sort = 0;
            foreach ($this->buildLines($deal, $record, $calc) as $line) {
                $line['invoice_id'] = $invoice->id;
                $line['sort_order'] = $sort++;
                $line['amount'] = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
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
     * @return array<int, array<string,mixed>>
     */
    private function buildLines(Deal $deal, ?WorkRecord $record, array $calc): array
    {
        $lines = [];
        $title = $deal->title ?: '業務委託料';
        $hoursLabel = $record?->actual_hours !== null ? sprintf(' (実働 %sh)', rtrim(rtrim((string) $record->actual_hours, '0'), '.')) : '';

        if ($calc['basic'] > 0) {
            $lines[] = [
                'description' => $title . $hoursLabel,
                'quantity'    => 1,
                'unit'        => '式',
                'unit_price'  => $calc['basic'],
                'tax_rate'    => 0.10,
            ];
        }
        if ($calc['deduction'] > 0) {
            $lines[] = [
                'description' => '控除（精算下限未達）',
                'quantity'    => 1,
                'unit'        => '式',
                'unit_price'  => -1 * $calc['deduction'],
                'tax_rate'    => 0.10,
            ];
        }
        if ($calc['overtime'] > 0) {
            $lines[] = [
                'description' => '超過（精算上限超）',
                'quantity'    => 1,
                'unit'        => '式',
                'unit_price'  => $calc['overtime'],
                'tax_rate'    => 0.10,
            ];
        }
        if ($calc['transportation'] > 0) {
            // 交通費は実費精算のため非課税扱い
            $lines[] = [
                'description' => '交通費（実費）',
                'quantity'    => 1,
                'unit'        => '式',
                'unit_price'  => $calc['transportation'],
                'tax_rate'    => 0.0,
            ];
        }

        if (empty($lines)) {
            // 何も計上できない場合は基本額0の1行を入れる（編集前提）
            $lines[] = [
                'description' => $title,
                'quantity'    => 1,
                'unit'        => '式',
                'unit_price'  => 0,
                'tax_rate'    => 0.10,
            ];
        }

        return $lines;
    }

    /**
     * 支払期限を算出する
     * 「当月末締め + N日後支払」（payment_site が日数）。
     * 例: 2026-04 / payment_site=50 → 2026-04-30 + 50d = 2026-06-19
     * payment_site が null の場合は 30日（翌月末相当）を既定値とする。
     */
    private function calculateDueDate(string $yearMonth, ?int $paymentSite): string
    {
        [$y, $m] = explode('-', $yearMonth);
        return Carbon::create((int) $y, (int) $m, 1)
            ->endOfMonth()
            ->addDays($paymentSite ?? 30)
            ->toDateString();
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
