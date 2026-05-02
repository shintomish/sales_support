<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\WorkRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 月別勤務記録 (work_records) の取得・upsert
 * deal_id + year_month で一意。
 */
class WorkRecordController extends Controller
{
    /**
     * GET /api/v1/deals/{deal}/work-records
     * 指定 deal の月別勤務記録を年月降順で返す
     */
    public function index(Deal $deal): JsonResponse
    {
        $records = WorkRecord::where('deal_id', $deal->id)
            ->orderBy('year_month', 'desc')
            ->get();

        return response()->json([
            'deal_id' => $deal->id,
            'records' => $records,
        ]);
    }

    /**
     * GET /api/v1/work-records?year_month=YYYY-MM&q=...
     * 全SES案件 × 指定月の勤務表ステータス一覧
     * work_record が無い案件も "未受領" として返す
     */
    public function indexAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'q'          => ['nullable', 'string', 'max:200'],
        ]);
        $ym = $validated['year_month'];

        $deals = Deal::with([
            'sesContract',
            'customer:id,company_name',
            'workRecords' => fn ($q) => $q->where('year_month', $ym),
        ])
            ->whereHas('sesContract')
            ->get();

        $rows = $deals->map(function (Deal $deal) {
            $r = $deal->workRecords->first();
            return [
                'deal_id'                 => $deal->id,
                'deal_title'              => $deal->title,
                'customer_id'             => $deal->customer?->id,
                'customer_name'           => $deal->customer?->company_name,
                'engineer_name'           => $deal->sesContract?->engineer_name,
                'timesheet_received_date' => $r?->timesheet_received_date,
                'actual_hours'            => $r?->actual_hours,
                'absence_days'            => $r?->absence_days,
                'paid_leave_days'         => $r?->paid_leave_days,
                'transportation_fee'      => $r?->transportation_fee,
                'invoice_exists'          => $r?->invoice_exists,
                'invoice_received_date'   => $r?->invoice_received_date,
                'notes'                   => $r?->notes,
            ];
        });

        if (!empty($validated['q'])) {
            $q = mb_strtolower($validated['q']);
            $rows = $rows->filter(function ($r) use ($q) {
                return str_contains(mb_strtolower((string) $r['deal_title']), $q)
                    || str_contains(mb_strtolower((string) $r['customer_name']), $q)
                    || str_contains(mb_strtolower((string) $r['engineer_name']), $q);
            })->values();
        }

        // 取引先名 → 案件IDで安定ソート
        $sorted = $rows->sort(function ($a, $b) {
            $cmp = strcmp((string) $a['customer_name'], (string) $b['customer_name']);
            return $cmp !== 0 ? $cmp : ($a['deal_id'] <=> $b['deal_id']);
        })->values();

        return response()->json(['items' => $sorted]);
    }

    /**
     * PUT /api/v1/deals/{deal}/work-records/{yearMonth}
     * 指定月のレコードを upsert（無ければ新規作成、あれば更新）
     */
    public function upsert(Request $request, Deal $deal, string $yearMonth): JsonResponse
    {
        $validated = $request->validate([
            'timesheet_received_date' => 'nullable|date',
            'transportation_fee'      => 'nullable|numeric|min:0',
            'absence_days'            => 'nullable|numeric|min:0',
            'paid_leave_days'         => 'nullable|numeric|min:0',
            'actual_hours'            => 'nullable|numeric|min:0',
            'invoice_exists'          => 'nullable|boolean',
            'invoice_received_date'   => 'nullable|date',
            'notes'                   => 'nullable|string|max:2000',
        ]);

        // year_month バリデーション (YYYY-MM)
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
            abort(422, 'year_month は YYYY-MM 形式で指定してください');
        }

        $record = WorkRecord::updateOrCreate(
            [
                'deal_id'    => $deal->id,
                'year_month' => $yearMonth,
                'tenant_id'  => $deal->tenant_id,
            ],
            $validated
        );

        return response()->json($record, 200);
    }

    /**
     * DELETE /api/v1/deals/{deal}/work-records/{yearMonth}
     */
    public function destroy(Deal $deal, string $yearMonth): JsonResponse
    {
        WorkRecord::where('deal_id', $deal->id)
            ->where('year_month', $yearMonth)
            ->delete();

        return response()->json(null, 204);
    }
}
