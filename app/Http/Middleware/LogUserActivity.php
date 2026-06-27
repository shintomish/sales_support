<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LogUserActivity
{
    private array $targetMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private array $excludePaths = [
        'api/v1/login',
        'api/v1/logout',
        'api/v1/dashboard',
        'login',
        'logout',
        'up',
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $method = $request->method();
        $path   = $request->path();

        foreach ($this->excludePaths as $exclude) {
            if ($path === $exclude || str_starts_with($path, $exclude . '/')) {
                return $response;
            }
        }

        if (!in_array($method, $this->targetMethods)) {
            return $response;
        }

        $user     = Auth::user();
        $userId   = $user?->id ?? 'guest';
        $userName = $user?->name ?? 'guest';
        $status   = $response->getStatusCode();

        // super_admin はテナントに属さないため 'super_admin' と表示
        $tenantId = $user?->tenant_id
            ?? ($user?->role === 'super_admin' ? 'super_admin' : '-');

        $action = match($method) {
            'GET'          => 'READ',
            'POST'         => 'CREATE',
            'PUT', 'PATCH' => 'UPDATE',
            'DELETE'       => 'DELETE',
            default        => $method,
        };

        $segments = explode('/', $path);
        $resource = $segments[2] ?? $path;

        // 監査ログの書き込み失敗（ファイル権限・ディスク等）でリクエストを致命化させない。
        // 例: audit-YYYY-MM-DD.log が root 所有だと www-data が追記できず例外→本来200が500化し
        //     ログイン不能になる事故が日付替わりごとに再発していた。stderr にだけ残して握り潰す。
        try {
            Log::channel('audit')->info('[USER_ACTIVITY]', [
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
        } catch (\Throwable $e) {
            error_log('[USER_ACTIVITY] audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function sanitizeParams(Request $request): array
    {
        return $request->except(['password', 'password_confirmation', 'token', '_token']);
    }
}
