<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkRecord;
use App\Services\BillingCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 月次請求集計（Phase B）
 *
 * 当該年月に work_records が存在する SES 案件を起点に、
 * 試算金額を案件×月 / 取引先×月 で集計する。
 */
class BillingSummaryController extends Controller
{
    public function __construct(
        private readonly BillingCalculationService $calculator,
    ) {}

    /**
     * GET /api/v1/billing-summaries?year_month=YYYY-MM&group=deal|customer
     */
    public function index(Request $request): JsonResponse
    {
        $params = $this->validatedParams($request);
        $rows = $this->buildDealRows($params);

        $items  = $params['group'] === 'customer' ? $this->groupByCustomer($rows) : $rows;
        $totals = $this->sumTotals($rows);

        return response()->json([
            'year_month' => $params['year_month'],
            'group'      => $params['group'],
            'items'      => $items,
            'totals'     => $totals,
        ]);
    }

    /**
     * GET /api/v1/billing-summaries/export.csv?year_month=YYYY-MM&group=deal|customer
     */
    public function export(Request $request): StreamedResponse
    {
        $params = $this->validatedParams($request);
        $rows = $this->buildDealRows($params);
        $items = $params['group'] === 'customer' ? $this->groupByCustomer($rows) : $rows;

        $filename = sprintf('billing-summary-%s-%s.csv', $params['year_month'], $params['group']);

        return new StreamedResponse(function () use ($params, $items) {
            $out = fopen('php://output', 'w');
            // Excel が開けるよう BOM 付き UTF-8
            fwrite($out, "\xEF\xBB\xBF");

            if ($params['group'] === 'customer') {
                fputcsv($out, ['取引先ID', '取引先名', '請求書コード', '案件数', '実労働時間', '基本額', '控除', '超過', '交通費', '小計', '消費税', '請求合計']);
                foreach ($items as $r) {
                    fputcsv($out, [
                        $r['customer_id'], $r['customer_name'], $r['invoice_code'] ?? '',
                        $r['deal_count'], $r['actual_hours'],
                        $r['basic'], $r['deduction'], $r['overtime'], $r['transportation'],
                        $r['subtotal'], $r['tax'], $r['total'],
                    ]);
                }
            } else {
                fputcsv($out, ['案件ID', '案件名', '取引先ID', '取引先名', '請求書コード', '技術者', '実労働時間', '基本額', '控除', '超過', '交通費', '小計', '消費税', '請求合計']);
                foreach ($items as $r) {
                    fputcsv($out, [
                        $r['deal_id'], $r['deal_title'],
                        $r['customer_id'], $r['customer_name'], $r['invoice_code'] ?? '',
                        $r['engineer_name'] ?? '', $r['actual_hours'],
                        $r['basic'], $r['deduction'], $r['overtime'], $r['transportation'],
                        $r['subtotal'], $r['tax'], $r['total'],
                    ]);
                }
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** クエリパラメータをバリデート */
    private function validatedParams(Request $request): array
    {
        $validated = $request->validate([
            'year_month'  => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'group'       => ['nullable', 'in:deal,customer'],
            'customer_id' => ['nullable', 'integer'],
            'q'           => ['nullable', 'string', 'max:200'],
        ]);

        return [
            'year_month'  => $validated['year_month'],
            'group'       => $validated['group'] ?? 'deal',
            'customer_id' => $validated['customer_id'] ?? null,
            'q'           => $validated['q'] ?? null,
        ];
    }

    /**
     * 案件×月 行リストを構築（customer グルーピング前のフラット行）
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildDealRows(array $params): array
    {
        $records = WorkRecord::with([
                'deal.customer',
                'deal.sesContract',
                'deal.invoices' => fn($q) => $q->where('year_month', $params['year_month']),
            ])
            ->where('year_month', $params['year_month'])
            ->whereHas('deal', function ($q) use ($params) {
                $q->where('deal_type', 'ses');
                if ($params['customer_id']) {
                    $q->where('customer_id', $params['customer_id']);
                }
                if ($params['q']) {
                    $q->where(function ($q2) use ($params) {
                        $like = '%' . $params['q'] . '%';
                        $q2->where('title', 'like', $like)
                           ->orWhereHas('customer', fn($cq) => $cq->where('company_name', 'like', $like));
                    });
                }
            })
            ->get();

        $rows = [];
        foreach ($records as $record) {
            $deal = $record->deal;
            if (!$deal) continue;

            $calc = $this->calculator->calculate($deal->sesContract, $record);
            $existingInvoice = $deal->invoices->first();

            $rows[] = [
                'deal_id'        => $deal->id,
                'deal_title'     => $deal->title,
                'customer_id'    => $deal->customer?->id,
                'customer_name'  => $deal->customer?->company_name,
                'invoice_code'   => $deal->customer?->invoice_code,
                'engineer_name'  => $deal->sesContract?->engineer_name,
                'actual_hours'   => $calc['actual_hours'],
                'basic'          => $calc['basic'],
                'deduction'      => $calc['deduction'],
                'overtime'       => $calc['overtime'],
                'transportation' => $calc['transportation'],
                'subtotal'       => $calc['subtotal'],
                'tax'            => $calc['tax'],
                'total'          => $calc['total'],
                'tax_rate'       => $calc['tax_rate'],
                'invoice_id'     => $existingInvoice?->id,
                'invoice_status' => $existingInvoice?->status,
            ];
        }

        // 取引先名 → 案件IDで安定ソート
        usort($rows, function ($a, $b) {
            $cmp = strcmp((string) $a['customer_name'], (string) $b['customer_name']);
            return $cmp !== 0 ? $cmp : ($a['deal_id'] <=> $b['deal_id']);
        });

        return $rows;
    }

    /** 取引先×月 で合算 */
    private function groupByCustomer(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $r) {
            $key = $r['customer_id'] ?? 0;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'customer_id'    => $r['customer_id'],
                    'customer_name'  => $r['customer_name'],
                    'invoice_code'   => $r['invoice_code'],
                    'deal_count'     => 0,
                    'actual_hours'   => 0.0,
                    'basic'          => 0.0,
                    'deduction'      => 0.0,
                    'overtime'       => 0.0,
                    'transportation' => 0.0,
                    'subtotal'       => 0.0,
                    'tax'            => 0.0,
                    'total'          => 0.0,
                ];
            }
            $grouped[$key]['deal_count']++;
            $grouped[$key]['actual_hours']   += (float) ($r['actual_hours'] ?? 0);
            foreach (['basic', 'deduction', 'overtime', 'transportation', 'subtotal', 'tax', 'total'] as $f) {
                $grouped[$key][$f] += (float) $r[$f];
            }
        }
        // 値は 2 桁丸め
        return array_map(function ($g) {
            foreach (['actual_hours', 'basic', 'deduction', 'overtime', 'transportation', 'subtotal', 'tax', 'total'] as $f) {
                $g[$f] = round($g[$f], 2);
            }
            return $g;
        }, array_values($grouped));
    }

    /** 全行の総計 */
    private function sumTotals(array $rows): array
    {
        $totals = [
            'basic' => 0.0, 'deduction' => 0.0, 'overtime' => 0.0,
            'transportation' => 0.0, 'subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0,
        ];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += (float) $r[$k];
            }
        }
        return array_map(fn($v) => round($v, 2), $totals);
    }
}
