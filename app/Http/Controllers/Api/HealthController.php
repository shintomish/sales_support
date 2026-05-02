<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * 死活監視エンドポイント
 *
 * UptimeRobot などの外部監視は __invoke (浅い health) を叩く。
 * DB やセッションを経由しないため、PHP-FPM が応答できる限り 200 を返す。
 * バッチ処理で DB が一時的に応答しなくても誤検知しない設計。
 *
 * 詳細チェックが必要なときは /api/v1/health/deep を別途使う。
 */
class HealthController extends Controller
{
    /** GET /api/v1/health  - 軽量チェック（PHP-FPM の生存確認のみ） */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'ts'     => now()->toIso8601String(),
        ], 200);
    }

    /** GET /api/v1/health/deep  - DB 含む詳細チェック */
    public function deep(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $db = 'ok';
        } catch (\Throwable $e) {
            $db = 'error';
        }

        $status = $db === 'ok' ? 'ok' : 'degraded';
        $code   = $status === 'ok' ? 200 : 503;

        return response()->json([
            'status' => $status,
            'db'     => $db,
            'ts'     => now()->toIso8601String(),
        ], $code);
    }
}
