<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Services\BillingCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 月次請求集計（Phase B）
 *
 * 対象年月に契約がかかる全 SES 案件を行として返す（勤務表未入力の案件も含む）。
 * 勤務表未入力行はこの画面で実時間を入力 → そのまま請求書作成できる（2026-06-24 管理部要望）。
 * 集計(totals)・取引先別・CSV は勤務表入力済(actualized)の行のみを対象とし、
 * 「確定済み請求の集計」という従来の意味を保つ。
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

        // 集計・取引先別は確定済み（勤務表入力済）行のみ。案件別は未入力行も表示（作成導線用）。
        $actualized = array_values(array_filter($rows, fn($r) => $r['has_work_record']));
        $items  = $params['group'] === 'customer' ? $this->groupByCustomer($actualized) : $rows;
        $totals = $this->sumTotals($actualized);

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
        // CSV は確定済み（勤務表入力済）行のみ＝従来の請求集計の意味を保つ
        $actualized = array_values(array_filter($rows, fn($r) => $r['has_work_record']));
        $items = $params['group'] === 'customer' ? $this->groupByCustomer($actualized) : $actualized;

        $filename = sprintf('billing-summary-%s-%s.csv', $params['year_month'], $params['group']);

        return new StreamedResponse(function () use ($params, $items) {
            $out = fopen('php://output', 'w');
            // Excel が開けるよう BOM 付き UTF-8
            fwrite($out, "\xEF\xBB\xBF");

            if ($params['group'] === 'customer') {
                fputcsv($out, ['取引先ID', '取引先名', '顧客コード', '案件数', '実労働時間', '基本額', '控除', '超過', '交通費', '小計', '消費税', '請求合計']);
                foreach ($items as $r) {
                    fputcsv($out, [
                        $r['customer_id'], $r['customer_name'], $r['invoice_code'] ?? '',
                        $r['deal_count'], $r['actual_hours'],
                        $r['basic'], $r['deduction'], $r['overtime'], $r['transportation'],
                        $r['subtotal'], $r['tax'], $r['total'],
                    ]);
                }
            } else {
                fputcsv($out, ['案件ID', '案件名', '取引先ID', '取引先名', '顧客コード', '技術者', '実労働時間', '基本額', '控除', '超過', '交通費', '小計', '消費税', '請求合計']);
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
        $ym       = $params['year_month'];
        $firstDay = $ym . '-01';
        $lastDay  = \Carbon\Carbon::createFromFormat('!Y-m-d', $firstDay)->endOfMonth()->toDateString();

        // 対象月に契約がかかる全 SES 案件（勤務表未入力も含む）。
        // 契約期間が対象月に重なる案件、または対象月の勤務表が既にある案件を取る。
        $deals = Deal::with([
                'customer',
                'sesContract',
                // 「発行状況」は請求書のみが対象（見積書/注文書は除外）
                'invoices'    => fn($q) => $q->where('year_month', $ym)->where('doc_type', 'invoice'),
                'workRecords' => fn($q) => $q->where('year_month', $ym),
            ])
            ->where('deal_type', 'ses')
            ->whereHas('sesContract')
            ->where(function ($q) use ($firstDay, $lastDay, $ym) {
                $q->whereHas('sesContract', function ($c) use ($firstDay, $lastDay) {
                    $c->where(function ($cc) use ($lastDay) {
                        $cc->whereNull('contract_period_start')
                           ->orWhereDate('contract_period_start', '<=', $lastDay);
                    })->where(function ($cc) use ($firstDay) {
                        $cc->whereNull('contract_period_end')
                           ->orWhereDate('contract_period_end', '>=', $firstDay);
                    });
                })
                // 契約期間外でも対象月の勤務表があれば取りこぼさない
                ->orWhereHas('workRecords', fn($w) => $w->where('year_month', $ym));
            })
            ->when($params['customer_id'], fn($q) => $q->where('customer_id', $params['customer_id']))
            ->when($params['q'], function ($q) use ($params) {
                $like = '%' . $params['q'] . '%';
                $q->where(function ($q2) use ($like) {
                    $q2->where('title', 'ilike', $like)
                       ->orWhereHas('customer', fn($cq) => $cq->where('company_name', 'ilike', $like));
                });
            })
            ->get();

        $rows = [];
        foreach ($deals as $deal) {
            $contract = $deal->sesContract;
            $record   = $deal->workRecords->first();   // null = 勤務表未入力
            $calc     = $this->calculator->calculate($contract, $record);
            $existingInvoice = $deal->invoices->first();

            $rows[] = [
                'deal_id'        => $deal->id,
                'deal_title'     => $deal->title,
                'customer_id'    => $deal->customer?->id,
                'customer_name'  => $deal->customer?->company_name,
                'invoice_code'   => $deal->customer?->invoice_code,
                'engineer_name'  => $contract?->engineer_name,
                'actual_hours'   => $calc['actual_hours'],
                'basic'          => $calc['basic'],
                'deduction'      => $calc['deduction'],
                'overtime'       => $calc['overtime'],
                'transportation' => $calc['transportation'],
                'subtotal'       => $calc['subtotal'],
                'tax'            => $calc['tax'],
                'total'          => $calc['total'],
                'tax_rate'       => $calc['tax_rate'],
                'has_work_record' => $record !== null,
                // 勤務表未入力行のクライアント側ライブ試算用に顧客側精算条件を返す
                'client_deduction_hours'      => $contract?->client_deduction_hours !== null ? (float) $contract->client_deduction_hours : null,
                'client_overtime_hours'       => $contract?->client_overtime_hours !== null ? (float) $contract->client_overtime_hours : null,
                'client_deduction_unit_price' => $contract?->client_deduction_unit_price !== null ? (float) $contract->client_deduction_unit_price : null,
                'client_overtime_unit_price'  => $contract?->client_overtime_unit_price !== null ? (float) $contract->client_overtime_unit_price : null,
                'invoice_id'       => $existingInvoice?->id,
                'invoice_status'   => $existingInvoice?->status,
                'invoice_pdf_path' => $existingInvoice?->pdf_path,
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
