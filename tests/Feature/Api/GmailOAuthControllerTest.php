<?php

namespace Tests\Feature\Api;

use App\Models\GmailToken;
use App\Services\GmailService;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class GmailOAuthControllerTest extends TestCase
{
    // ─── redirect ───

    public function test_redirect_returns_auth_url(): void
    {
        $this->actingAsUser();

        $this->mock(GmailService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getAuthUrl')
                ->once()
                ->with($this->authUser->id)
                ->andReturn('https://accounts.google.com/o/oauth2/v2/auth?client_id=stub');
        });

        $res = $this->getJson('/api/v1/gmail/redirect');

        $res->assertOk()->assertJson(['url' => 'https://accounts.google.com/o/oauth2/v2/auth?client_id=stub']);
    }

    public function test_redirect_requires_authentication(): void
    {
        $this->getJson('/api/v1/gmail/redirect')->assertUnauthorized();
    }

    // ─── callback ───

    public function test_callback_redirects_with_error_when_code_missing(): void
    {
        $res = $this->get('/api/v1/gmail/callback');

        $res->assertRedirect();
        $this->assertStringContainsString('error=no_code', $res->headers->get('Location'));
    }

    public function test_callback_persists_token_and_redirects_on_success(): void
    {
        // GmailService::exchangeCode をモック
        $this->mock(GmailService::class, function (MockInterface $mock) {
            $mock->shouldReceive('exchangeCode')
                ->once()
                ->with('auth-code-stub')
                ->andReturn([
                    'access_token'  => 'access-stub',
                    'refresh_token' => 'refresh-stub',
                    'expires_in'    => 3600,
                ]);
        });

        // userinfo の HTTP 呼び出しをフェイク
        Http::fake([
            'https://www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'user@example.com',
            ]),
        ]);

        // state はユーザーID。actingAs はリダイレクトを伴う非API呼び出しでは挙動が異なるので
        // 認証は使わず、既存ユーザーの ID を直接 state に渡す。
        $tenant = \App\Models\Tenant::factory()->create();
        $user   = \App\Models\User::factory()->tenantUser($tenant)->create();

        $res = $this->get("/api/v1/gmail/callback?code=auth-code-stub&state={$user->id}");

        $res->assertRedirect();
        $this->assertStringContainsString('connected=1', $res->headers->get('Location'));

        $this->assertDatabaseHas('gmail_tokens', [
            'tenant_id'     => $tenant->id,
            'user_id'       => $user->id,
            'gmail_address' => 'user@example.com',
        ]);
    }

    public function test_callback_redirects_with_error_when_exchange_fails(): void
    {
        $this->mock(GmailService::class, function (MockInterface $mock) {
            $mock->shouldReceive('exchangeCode')
                ->once()
                ->andThrow(new \Exception('Gmail token exchange failed'));
        });

        $res = $this->get('/api/v1/gmail/callback?code=bad&state=1');

        $res->assertRedirect();
        $this->assertStringContainsString('error=oauth_failed', $res->headers->get('Location'));
    }

    // ─── status ───

    public function test_status_returns_disconnected_when_no_token(): void
    {
        $this->actingAsUser();

        $res = $this->getJson('/api/v1/gmail/status');

        $res->assertOk()->assertJson([
            'connected'     => false,
            'gmail_address' => null,
        ]);
    }

    public function test_status_returns_connected_when_token_exists(): void
    {
        $this->actingAsUser();

        GmailToken::create([
            'tenant_id'        => $this->authUser->tenant_id,
            'user_id'          => $this->authUser->id,
            'gmail_address'    => 'connected@example.com',
            'access_token'     => 'tok',
            'refresh_token'    => 'rtok',
            'token_expires_at' => now()->addHour(),
        ]);

        $res = $this->getJson('/api/v1/gmail/status');

        $res->assertOk()->assertJson([
            'connected'     => true,
            'gmail_address' => 'connected@example.com',
        ]);
    }

    public function test_status_only_sees_own_tenant_token(): void
    {
        $this->actingAsUser();

        // 別テナントのトークン
        $otherTenant = \App\Models\Tenant::factory()->create();
        $otherUser   = \App\Models\User::factory()->tenantUser($otherTenant)->create();
        GmailToken::create([
            'tenant_id'        => $otherTenant->id,
            'user_id'          => $otherUser->id,
            'gmail_address'    => 'other@example.com',
            'access_token'     => 'tok',
            'token_expires_at' => now()->addHour(),
        ]);

        $res = $this->getJson('/api/v1/gmail/status');

        $res->assertOk()->assertJson(['connected' => false]);
    }

    public function test_status_requires_authentication(): void
    {
        $this->getJson('/api/v1/gmail/status')->assertUnauthorized();
    }

    // ─── disconnect ───

    public function test_disconnect_deletes_token(): void
    {
        $this->actingAsUser();

        GmailToken::create([
            'tenant_id'        => $this->authUser->tenant_id,
            'user_id'          => $this->authUser->id,
            'gmail_address'    => 'connected@example.com',
            'access_token'     => 'tok',
            'token_expires_at' => now()->addHour(),
        ]);

        $res = $this->deleteJson('/api/v1/gmail/disconnect');

        $res->assertOk()->assertJson(['message' => 'Disconnected']);
        $this->assertDatabaseMissing('gmail_tokens', [
            'tenant_id' => $this->authUser->tenant_id,
        ]);
    }

    public function test_disconnect_does_not_affect_other_tenants(): void
    {
        $this->actingAsUser();

        $otherTenant = \App\Models\Tenant::factory()->create();
        $otherUser   = \App\Models\User::factory()->tenantUser($otherTenant)->create();
        $otherToken  = GmailToken::create([
            'tenant_id'        => $otherTenant->id,
            'user_id'          => $otherUser->id,
            'gmail_address'    => 'other@example.com',
            'access_token'     => 'tok',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->deleteJson('/api/v1/gmail/disconnect')->assertOk();

        $this->assertDatabaseHas('gmail_tokens', ['id' => $otherToken->id]);
    }

    public function test_disconnect_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/gmail/disconnect')->assertUnauthorized();
    }
}
