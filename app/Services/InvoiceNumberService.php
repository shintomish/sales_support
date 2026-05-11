<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use RuntimeException;

/**
 * 請求書/見積書 番号採番サービス（Phase C）
 *
 * フォーマット:
 *   - 請求書 (doc_type=invoice):  INV-[customer.invoice_code]-YYYYMM-NNN   （3桁連番）
 *   - 見積書 (doc_type=estimate): EST-[customer.invoice_code]-YYYYMM-NNNN  （4桁連番）
 *
 * 連番は customer × year_month × doc_type 内で 1 から採番。
 * customer.invoice_code が空の顧客は採番不可（RuntimeException）。
 * 同時実行は invoices.invoice_number の UNIQUE 制約で担保し、
 * 衝突時は最大3回までリトライ。
 */
class InvoiceNumberService
{
    private const MAX_RETRY = 3;

    /** doc_type ごとの prefix / 連番桁数 */
    private const FORMATS = [
        'invoice'  => ['prefix' => 'INV', 'pad' => 3],
        'estimate' => ['prefix' => 'EST', 'pad' => 4],
    ];

    public function generate(Customer $customer, string $yearMonth, string $docType = 'invoice'): string
    {
        if (empty($customer->invoice_code)) {
            throw new RuntimeException('顧客に invoice_code が設定されていません: ' . $customer->id);
        }
        if (!isset(self::FORMATS[$docType])) {
            throw new RuntimeException('未対応の doc_type: ' . $docType);
        }

        $fmt = self::FORMATS[$docType];

        // YYYY-MM → YYYYMM
        $yearMonthCompact = str_replace('-', '', $yearMonth);
        $prefix = sprintf('%s-%s-%s-', $fmt['prefix'], $customer->invoice_code, $yearMonthCompact);

        for ($attempt = 0; $attempt < self::MAX_RETRY; $attempt++) {
            $next = $this->nextSequence($customer->id, $yearMonth, $docType);
            $candidate = $prefix . str_pad((string) $next, $fmt['pad'], '0', STR_PAD_LEFT);

            if (!Invoice::withoutGlobalScopes()->where('invoice_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('番号の採番に失敗しました（リトライ上限）');
    }

    /**
     * 当該 customer × year_month × doc_type の既存最大連番 +1 を返す。
     * 件数は通常 N=数十程度のため PHP 側で抽出（SQLite 互換のため）。
     */
    private function nextSequence(int $customerId, string $yearMonth, string $docType): int
    {
        $numbers = Invoice::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->where('doc_type', $docType)
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
