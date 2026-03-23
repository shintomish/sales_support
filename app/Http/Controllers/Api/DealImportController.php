<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DealImportLog;
use App\Services\DealImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

/**
 * DealImportController
 *
 * Excel（.xlsm / .xlsx）のアップロードとインポート結果の取得を担当する。
 *
 * エンドポイント:
 *   POST   /api/v1/deals/import         ← ファイルアップロード＆インポート実行
 *   GET    /api/v1/deals/import/logs    ← インポート履歴一覧
 *   GET    /api/v1/deals/import/logs/{id} ← インポート詳細（エラー一覧含む）
 */
class DealImportController extends Controller
{
    /**
     * Excel ファイルをアップロードしてインポートを実行する
     *
     * POST /api/v1/deals/import
     *
     * Request:
     *   - file: Excel ファイル（.xlsm / .xlsx）最大 10MB
     *
     * Response 200:
     * {
     *   "message": "インポートが完了しました",
     *   "log": {
     *     "id": 1,
     *     "status": "completed",
     *     "total_rows": 77,
     *     "created_count": 50,
     *     "updated_count": 27,
     *     "skipped_count": 0,
     *     "error_count": 0,
     *     "original_filename": "販売システム20260302.xlsm",
     *     "started_at": "2026-03-23T10:00:00+09:00",
     *     "completed_at": "2026-03-23T10:00:05+09:00"
     *   }
     * }
     */
    public function store(Request $request): JsonResponse
    {
        // バリデーション
        $request->validate([
            'file' => [
                'required',
                File::types(['xlsx', 'xlsm', 'xls'])
                    ->max(10 * 1024), // 10MB
            ],
        ], [
            'file.required' => 'ファイルを選択してください。',
            'file.mimes'    => 'Excel ファイル（.xlsx / .xlsm）のみアップロードできます。',
            'file.max'      => 'ファイルサイズは 10MB 以下にしてください。',
        ]);

        $uploadedFile = $request->file('file');
        $filename     = $uploadedFile->getClientOriginalName();

        // 一時ファイルとして保存
        $tmpPath = $uploadedFile->store('imports/tmp', 'local');
        $fullTmpPath = Storage::disk('local')->path($tmpPath);

        try {
            $service = new DealImportService(
                tenantId:   auth()->user()->tenant_id,
                importedBy: auth()->id(),
            );

            $log = $service->import($fullTmpPath, $filename);

        } finally {
            // 一時ファイルを削除
            Storage::disk('local')->delete($tmpPath);
        }

        $statusCode = $log->status === 'failed' ? 422 : 200;

        return response()->json([
            'message' => $log->status === 'failed'
                ? 'インポートに失敗しました。'
                : "インポートが完了しました（新規: {$log->created_count}件、更新: {$log->updated_count}件）",
            'log' => $this->formatLog($log),
        ], $statusCode);
    }

    /**
     * インポート履歴一覧を返す
     *
     * GET /api/v1/deals/import/logs
     *
     * Response 200:
     * { "logs": [ { ...log } ] }
     */
    public function logs(Request $request): JsonResponse
    {
        $logs = DealImportLog::where('tenant_id', auth()->user()->tenant_id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($log) => $this->formatLog($log));

        return response()->json(['logs' => $logs]);
    }

    /**
     * インポート詳細（エラー一覧含む）を返す
     *
     * GET /api/v1/deals/import/logs/{id}
     */
    public function showLog(int $id): JsonResponse
    {
        $log = DealImportLog::where('tenant_id', auth()->user()->tenant_id)
            ->findOrFail($id);

        return response()->json(['log' => $this->formatLog($log, withErrors: true)]);
    }

    // ── プライベートヘルパー ──────────────────────────────

    private function formatLog(DealImportLog $log, bool $withErrors = false): array
    {
        $data = [
            'id'                => $log->id,
            'status'            => $log->status,
            'original_filename' => $log->original_filename,
            'file_type'         => $log->file_type,
            'total_rows'        => $log->total_rows,
            'created_count'     => $log->created_count,
            'updated_count'     => $log->updated_count,
            'skipped_count'     => $log->skipped_count,
            'error_count'       => $log->error_count,
            'started_at'        => $log->started_at?->toIso8601String(),
            'completed_at'      => $log->completed_at?->toIso8601String(),
        ];

        if ($withErrors) {
            $data['error_details'] = $log->error_details ?? [];
        }

        return $data;
    }
}
