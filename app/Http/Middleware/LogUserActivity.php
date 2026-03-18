<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LogUserActivity
{
    // 記録対象のHTTPメソッド
    private array $targetMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    // ログに含めないパス
    private array $excludePaths = [
        'api/v1/login',
        'api/v1/logout',
        'api/v1/dashboard',
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $method = $request->method();
        $path   = $request->path();

        // 除外パスはスキップ
        foreach ($this->excludePaths as $exclude) {
            if (str_starts_with($path, $exclude)) {
                return $response;
            }
        }

        if (!in_array($method, $this->targetMethods)) {
            return $response;
        }

        $user     = Auth::user();
        $userId   = $user?->id ?? 'guest';
        $userName = $user?->name ?? 'guest';
        $tenantId = $user?->tenant_id ?? '-';
        $status   = $response->getStatusCode();

        // 操作種別
        $action = match($method) {
            'GET'    => 'READ',
            'POST'   => 'CREATE',
            'PUT', 'PATCH' => 'UPDATE',
            'DELETE' => 'DELETE',
            default  => $method,
        };

        // リソース名（URLから推測）
        $segments = explode('/', $path);
        $resource = $segments[2] ?? $path; // api/v1/{resource}

        // ログ出力
        Log::channel('daily')->info('[USER_ACTIVITY]', [
            'action'    => $action,
            'resource'  => $resource,
            'path'      => $path,
            'method'    => $method,
            'status'    => $status,
            'user_id'   => $userId,
            'user_name' => $userName,
            'tenant_id' => $tenantId,
            'ip'        => $request->ip(),
            'params'    => $this->sanitizeParams($request),
        ]);

        return $response;
    }

    private function sanitizeParams(Request $request): array
    {
        // パスワード等の機密情報は除外
        return $request->except(['password', 'password_confirmation', 'token']);
    }
}
