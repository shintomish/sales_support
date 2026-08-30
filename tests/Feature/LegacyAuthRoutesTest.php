<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 旧 Blade 側のセルフサービス認証ルートは Supabase Auth に一本化済みのため
 * 404 で閉じている。ビューを持たないまま公開すると View not found の 500 になる
 * (2026-08-27 Sentry: View [auth.forgot-password] not found)。
 */
class LegacyAuthRoutesTest extends TestCase
{
    public static function closedRouteProvider(): array
    {
        return [
            'register'        => ['GET', '/register'],
            'forgot-password' => ['GET', '/forgot-password'],
            'reset-password'  => ['GET', '/reset-password/dummy-token'],
        ];
    }

    #[DataProvider('closedRouteProvider')]
    public function test_legacy_auth_routes_are_closed(string $method, string $uri): void
    {
        $this->call($method, $uri)->assertNotFound();
    }

    public function test_login_page_is_still_available(): void
    {
        $this->get('/login')->assertOk();
    }
}
