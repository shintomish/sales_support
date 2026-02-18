<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * ログイン
     */
    public function login(Request $request)
    {
        // バリデーション
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        // 認証試行
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => '認証情報が正しくありません'
            ], 401);
        }

        // トークン生成
        $user = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ]);
    }

    /**
     * ログアウト
     */
    public function logout(Request $request)
    {
        // 現在のトークンを削除
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'ログアウト完了'
        ]);
    }

    /**
     * ログイン中のユーザー情報取得
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}