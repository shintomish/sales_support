<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GmailToken;
use App\Services\GmailService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class GmailOAuthController extends Controller
{
    public function __construct(private GmailService $gmailService) {}

    // 認可URLを返す → フロントがリダイレクト
    public function redirect()
    {
        $url = $this->gmailService->getAuthUrl(auth()->id());
        return response()->json(['url' => $url]);
    }

    // Googleからのコールバック
    //
    // 2026-05-14 以降、Gmail 取込は Kagoya IMAP に一本化され、新規 Gmail OAuth 接続は廃止済。
    // 旧実装は state パラメータを user_id 整数のまま信用しており、攻撃者が被害者の user_id を
    // state に詰めて自分の Google アカウントを被害者テナントに紐づける IDOR が成立していた
    // (docs/730_quality_review_2026_05_30.md §High #1)。
    // 新規 OAuth フロー UI は撤去済 (`/emails` 画面の Gmail 接続ボタン削除済) のため、
    // callback は何も受け付けず redirect で閉じることで攻撃面を消去する。
    public function callback(Request $request)
    {
        return redirect(config('app.frontend_url') . '/emails?error=oauth_disabled');
    }

    // 接続状態確認
    public function status()
    {
        $user  = auth()->user();
        $token = GmailToken::where('tenant_id', $user->tenant_id)->first();

        return response()->json([
            'connected'     => (bool) $token,
            'gmail_address' => $token?->gmail_address,
        ]);
    }

    // 接続解除
    public function disconnect()
    {
        $user = auth()->user();
        GmailToken::where('tenant_id', $user->tenant_id)->delete();
        return response()->json(['message' => 'Disconnected']);
    }
}
