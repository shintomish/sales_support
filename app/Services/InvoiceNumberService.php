<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use RuntimeException;

/**
 * 請求書/見積書/注文書/注文請書 番号採番サービス
 *
 * フォーマット:
 *   - 請求書       (kind=invoice):         INV-[customer.invoice_code]-YYYYMM-NNN   （3桁連番）
 *   - 見積書       (kind=estimate):        EST-[customer.invoice_code]-YYYYMM-NNNN  （4桁連番）
 *   - 注文書       (kind=purchase_order):  PO-[customer.invoice_code]-YYYYMM-NNN    （3桁連番）
 *   - 注文請書     (kind=acknowledgement): UKE-[customer.invoice_code]-YYYYMM-NNN   （3桁連番）
 *
 * 連番は customer × year_month × kind 内で 1 から採番。
 * acknowledgement は doc_type='purchase_order' 行の acknowledgement_no カラムに保存するため、
 * 連番計算もそのカラム経由で行う（他の kind は invoice_number カラム）。
 *
 * customer.invoice_code が空の顧客は採番不可（RuntimeException）。
 * 同時実行は UNIQUE 制約相当でアプリ層が担保し、衝突時は最大3回までリトライ。
 */
class InvoiceNumberService
{
    private const MAX_RETRY = 3;

    /**
     * kind ごとの prefix / 連番桁数 / 対象 doc_type / 採番対象カラム
     */
    private const FORMATS = [
        'invoice'         => ['prefix' => 'INV', 'pad' => 3, 'doc_type' => 'invoice',        'column' => 'invoice_number'],
        'estimate'        => ['prefix' => 'EST', 'pad' => 4, 'doc_type' => 'estimate',       'column' => 'invoice_number'],
        'purchase_order'  => ['prefix' => 'PO',  'pad' => 3, 'doc_type' => 'purchase_order', 'column' => 'invoice_number'],
        'acknowledgement' => ['prefix' => 'UKE', 'pad' => 3, 'doc_type' => 'purchase_order', 'column' => 'acknowledgement_no'],
    ];

    public function generate(Customer $customer, string $yearMonth, string $kind = 'invoice'): string
    {
        if (empty($customer->invoice_code)) {
            throw new RuntimeException('顧客に invoice_code が設定されていません: ' . $customer->id);
        }
        if (!isset(self::FORMATS[$kind])) {
            throw new RuntimeException('未対応の kind: ' . $kind);
        }

        $fmt = self::FORMATS[$kind];

        // YYYY-MM → YYYYMM
        $yearMonthCompact = str_replace('-', '', $yearMonth);
        $prefix = sprintf('%s-%s-%s-', $fmt['prefix'], $customer->invoice_code, $yearMonthCompact);

        for ($attempt = 0; $attempt < self::MAX_RETRY; $attempt++) {
            $next = $this->nextSequence($customer->id, $yearMonth, $fmt['doc_type'], $fmt['column']);
            $candidate = $prefix . str_pad((string) $next, $fmt['pad'], '0', STR_PAD_LEFT);

            if (!Invoice::withoutGlobalScopes()->where($fmt['column'], $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('番号の採番に失敗しました（リトライ上限）');
    }

    /**
     * 当該 customer × year_month × doc_type の指定カラム末尾連番 +1 を返す。
     * 件数は通常 N=数十程度のため PHP 側で抽出（SQLite 互換のため）。
     */
    private function nextSequence(int $customerId, string $yearMonth, string $docType, string $column): int
    {
        $numbers = Invoice::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->where('year_month', $yearMonth)
            ->where('doc_type', $docType)
            ->pluck($column);

        $max = 0;
        foreach ($numbers as $n) {
            if (preg_match('/(\d+)$/', (string) $n, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max + 1;
    }
}
