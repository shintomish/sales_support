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
    //
    // 2026-05-14 以降、Gmail 新規 OAuth 接続は廃止済（Kagoya IMAP に一本化）。
    // 旧 callback は state=user_id を信用しており IDOR が成立していたため
    // (docs/730_quality_review_2026_05_30.md §High #1)、callback は何も受け付けず
    // oauth_disabled へ redirect する。以下はその「攻撃面が閉じていること」の回帰ガード。

    public function test_callback_always_redirects_with_oauth_disabled(): void
    {
        $res = $this->get('/api/v1/gmail/callback');

        $res->assertRedirect();
        $this->assertStringContainsString('error=oauth_disabled', $res->headers->get('Location'));
    }

    public function test_callback_ignores_code_and_state_and_persists_nothing(): void
    {
        // code / state を渡されても、トークン交換も外部呼び出しもしない
        $this->mock(GmailService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('exchangeCode');
        });
        Http::fake();

        // 旧 IDOR の再現形: 被害者の user_id を state に詰める
        $tenant = \App\Models\Tenant::factory()->create();
        $victim = \App\Models\User::factory()->tenantUser($tenant)->create();

        $res = $this->get("/api/v1/gmail/callback?code=attacker-code&state={$victim->id}");

        $res->assertRedirect();
        $location = $res->headers->get('Location');
        $this->assertStringContainsString('error=oauth_disabled', $location);
        $this->assertStringNotContainsString('connected=1', $location);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('gmail_tokens', ['user_id' => $victim->id]);
        $this->assertDatabaseCount('gmail_tokens', 0);
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
