<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * テナント内ユーザー一覧（担当者フィルタ用）
     * super_admin は全テナント or ?tenant_id= で絞り込み可
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = User::select('id', 'name', 'email', 'role', 'tenant_id')
            ->orderBy('name');

        if ($user->isSuperAdmin()) {
            if ($request->tenant_id) {
                $query->where('tenant_id', $request->tenant_id);
            }
        } else {
            $query->where('tenant_id', $user->tenant_id);
        }

        return response()->json($query->get());
    }
}
