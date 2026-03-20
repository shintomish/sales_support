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
    public function callback(Request $request)
    {
        $code  = $request->query('code');
        $state = $request->query('state'); // user_idを受け取る

        if (!$code || !$state) {
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/emails?error=no_code');
        }

        try {
            $tokens = $this->gmailService->exchangeCode($code);

            // userinfo取得
            $userInfo = \Illuminate\Support\Facades\Http::withToken($tokens['access_token'])
                ->get('https://www.googleapis.com/oauth2/v2/userinfo')
                ->json();

            $gmailAddress = $userInfo['email'];

            // stateからユーザーを特定
            $user = \App\Models\User::find((int) $state);
            if (!$user) {
                return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/emails?error=oauth_failed');
            }

            GmailToken::updateOrCreate(
                [
                    'tenant_id'     => $user->tenant_id,
                    'gmail_address' => $gmailAddress,
                ],
                [
                    'user_id'          => $user->id,
                    'access_token'     => $tokens['access_token'],
                    'refresh_token'    => $tokens['refresh_token'] ?? null,
                    'token_expires_at' => \Carbon\Carbon::now()->addSeconds(($tokens['expires_in'] ?? 3600) - 60),
                ]
            );

            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/emails?connected=1');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gmail OAuth callback error: ' . $e->getMessage());
            return redirect(env('FRONTEND_URL', 'http://localhost:3000') . '/emails?error=oauth_failed');
        }
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
