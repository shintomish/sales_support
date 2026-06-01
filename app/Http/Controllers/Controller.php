<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /**
     * super_admin であっても destructive ops は自テナント所属に限定するガード。
     *
     * super_admin は TenantScope を bypass するため Route Model Binding で他テナント
     * のモデルが解決され得る。読み取りは許容するが update/destroy 等の破壊操作は
     * 誤操作で他テナントデータを壊さないよう明示的に弾く (docs/730 #14 Medium)。
     */
    protected function ensureSameTenantForDestructive(Model $model): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(401);
        }
        // tenant_user / tenant_admin は TenantScope で既に弾かれている
        if ($user->role !== 'super_admin') {
            return;
        }
        // tenant_id を持たないモデルはスコープ外
        if (!isset($model->tenant_id)) {
            return;
        }
        if ((int) $model->tenant_id !== (int) $user->tenant_id) {
            abort(403, 'super_admin でも他テナントのデータを変更・削除することはできません');
        }
    }

    /**
     * 担当者フィルタ用の user_id を解決する。
     *
     * - tenant_user: デフォルト=自分のID。?user_id=all で全員表示
     * - tenant_admin: デフォルト=全員(null)。?user_id={id} で個人絞り込み
     * - super_admin : デフォルト=全員(null)。?user_id={id} で個人絞り込み
     *
     * @return int|null  nullの場合はフィルタなし（全員）
     */
    protected function resolveUserFilter(Request $request): ?int
    {
        $user = $request->user();

        // 明示的に特定ユーザーが指定された場合
        if ($request->filled('user_id') && $request->user_id !== 'all') {
            return (int) $request->user_id;
        }

        // tenant_user はデフォルトで自分のデータのみ
        if ($user->isTenantUser() && !$request->has('user_id')) {
            return $user->id;
        }

        // tenant_admin / super_admin: フィルタなし
        return null;
    }

    /**
     * ソートパラメータを解決する。
     *
     * @param  array<string,string> $allowedColumns  フロント側キー => DBカラム名
     * @return array{0:string, 1:string}  [$column, $direction]
     */
    protected function resolveSort(Request $request, array $allowedColumns, string $default, string $defaultOrder = 'asc'): array
    {
        $sortBy    = $request->get('sort_by');
        $sortOrder = $request->get('sort_order') === 'desc' ? 'desc' : 'asc';

        if ($sortBy && isset($allowedColumns[$sortBy])) {
            return [$allowedColumns[$sortBy], $sortOrder];
        }
        return [$default, $defaultOrder];
    }
}
