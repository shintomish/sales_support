<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SupabaseAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(["message" => "Unauthenticated."], 401);
        }

        try {
            $jwksUrl = config('services.supabase.jwks_url');
            if (!$jwksUrl) {
                // SUPABASE_JWKS_URL が .env / config:cache に無い場合は明示的に 500
                // (Http::get(null) で TypeError を出すより診断しやすい)
                return response()->json(['message' => 'Auth config missing: supabase.jwks_url'], 500);
            }
            $jwks = Cache::remember("supabase_jwks", 3600, function () use ($jwksUrl) {
                $response = Http::get($jwksUrl);
                $data = $response->successful() ? $response->json() : null;
                // 異常応答時は null を返してキャッシュ汚染を防ぐ (Cache::remember は null も保存するため例外で抜ける)
                if (!is_array($data) || empty($data['keys'])) {
                    throw new \RuntimeException('Supabase JWKS fetch failed (status=' . $response->status() . ')');
                }
                return $data;
            });

            $keys = JWK::parseKeySet($jwks);
            JWT::$leeway = 60;
            $decoded = JWT::decode($token, $keys);

            $supabaseUid = $decoded->sub ?? null;
            if (!$supabaseUid) {
                return response()->json(["message" => "Invalid token."], 401);
            }

            // User lookup を 60秒キャッシュ（role/tenant 変更は 1分以内に反映）
            $user = Cache::remember(
                "user_by_supabase_uid:{$supabaseUid}",
                60,
                fn() => User::where("supabase_uid", $supabaseUid)->first()
            );
            if (!$user) {
                return response()->json(["message" => "User not found."], 401);
            }

            auth()->setUser($user);

        } catch (\Exception $e) {
            return response()->json(["message" => "Token invalid: " . $e->getMessage()], 401);
        }

        return $next($request);
    }
}
