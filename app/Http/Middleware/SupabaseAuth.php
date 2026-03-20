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
            $jwks = Cache::remember("supabase_jwks", 3600, function () {
                $response = Http::get(env("SUPABASE_JWKS_URL"));
                return $response->json();
            });

            $keys = JWK::parseKeySet($jwks);
            JWT::$leeway = 60;
            $decoded = JWT::decode($token, $keys);

            $supabaseUid = $decoded->sub ?? null;
            if (!$supabaseUid) {
                return response()->json(["message" => "Invalid token."], 401);
            }

            $user = User::where("supabase_uid", $supabaseUid)->first();
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
