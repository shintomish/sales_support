<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use RuntimeException;

/**
 * 請求書番号採番サービス（Phase C）
 *
 * フォーマット: INV-[customer.invoice_code]-YYYYMM-NNN
 *   NNN は customer × year_month 内で 001 から連番
 *
 * customer.invoice_code が空の顧客は採番不可（RuntimeException）。
 * 同時実行は invoices.invoice_number の UNIQUE 制約で担保し、
 * 衝突時は最大3回までリトライ。
 */
class InvoiceNumberService
{
    private const MAX_RETRY = 3;

    public function generate(Customer $customer, string $yearMonth): string
    {
        if (empty($customer->invoice_code)) {
            throw new RuntimeException('顧客に invoice_code が設定されていません: ' . $customer->id);
        }

        // YYYY-MM → YYYYMM
        $yearMonthCompact = str_replace('-', '', $yearMonth);
        $prefix = sprintf('INV-%s-%s-', $customer->invoice_code, $yearMonthCompact);

        for ($attempt = 0; $attempt < self::MAX_RETRY; $attempt++) {
            $next = $this->nextSequence($customer->id, $yearMonth);
            $candidate = $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);

            if (!Invoice::withoutGlobalScopes()->where('invoice_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('請求書番号の採番に失敗しました（リトライ上限）');
    }

    /**
     * 当該 customer × year_month の既存最大連番 +1 を返す。
     * 件数は通常 N=数十程度のため PHP 側で抽出（SQLite 互換のため）。
     */
    private function nextSequence(int $customerId, string $yearMonth): int
    {
        $numbers = Invoice::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->pluck('invoice_number');

        $max = 0;
        foreach ($numbers as $n) {
            if (preg_match('/(\d+)$/', (string) $n, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max + 1;
    }
}
