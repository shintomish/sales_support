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
