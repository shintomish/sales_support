<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
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
