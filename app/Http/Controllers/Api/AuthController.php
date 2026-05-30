<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    // 旧 login / logout (Sanctum ベース) は削除済 (docs/730 §Low #41)。
    // FE は Supabase Auth (supabase.auth.signInWithPassword / signOut) を直接使い、
    // 他全エンドポイントは middleware 'supabase.auth' で JWT 検証する設計に統一されている。

    public function me(Request $request)
    {
        $user = $request->user()->load('tenant');

        return response()->json([
            'id'        => $user->id,
            'name'      => $user->name,
            'email'     => $user->email,
            'role'      => $user->role,
            'tenant_id' => $user->tenant_id,
            'tenant'    => $user->tenant,
        ]);
    }
}
