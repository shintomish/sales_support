<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // DBへの疎通確認
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
