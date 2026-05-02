<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Issue #15: 管理画面 機能別データ統計ダッシュボードのアクセス制御テスト
 *
 * Storage 容量取得は外部 HTTP コールを伴うので Http::fake で空応答に差し替える。
 */
class AdminStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake([
            '*/storage/v1/object/list/*' => Http::response([], 200),
        ]);
    }

    private function actingAsRole(string $role, ?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::factory()->create();
        $factory = User::factory();
        $user = match ($role) {
            'super_admin'  => $factory->superAdmin($tenant)->create(),
            'tenant_admin' => $factory->tenantAdmin($tenant)->create(),
            default        => $factory->tenantUser($tenant)->create(),
        };
        $this->actingAs($user)->withoutMiddleware(\App\Http\Middleware\SupabaseAuth::class);
        return $user;
    }

    public function test_super_admin_can_view_self_scope(): void
    {
        $admin = $this->actingAsRole('super_admin');

        $res = $this->getJson('/api/v1/admin/stats');

        $res->assertOk()
            ->assertJsonStructure(['scope', 'tenant_id', 'period_days', 'generated_at', 'stats' => ['customers']]);
        $this->assertSame('self', $res->json('scope'));
        $this->assertSame(30, $res->json('period_days'));
    }

    public function test_period_can_be_changed(): void
    {
        $this->actingAsRole('super_admin');

        $res = $this->getJson('/api/v1/admin/stats?period=7');

        $res->assertOk();
        $this->assertSame(7, $res->json('period_days'));
    }

    public function test_invalid_period_falls_back_to_30(): void
    {
        $this->actingAsRole('super_admin');

        $res = $this->getJson('/api/v1/admin/stats?period=999');

        $res->assertOk();
        $this->assertSame(30, $res->json('period_days'));
    }

    public function test_super_admin_can_view_all_tenants(): void
    {
        $this->actingAsRole('super_admin');

        $res = $this->getJson('/api/v1/admin/stats?tenant_id=all');

        $res->assertOk();
        $this->assertSame('all', $res->json('scope'));
        $this->assertNull($res->json('tenant_id'));
    }

    public function test_super_admin_can_filter_by_tenant_id(): void
    {
        $this->actingAsRole('super_admin');
        $other = Tenant::factory()->create();

        $res = $this->getJson("/api/v1/admin/stats?tenant_id={$other->id}");

        $res->assertOk();
        $this->assertSame('tenant', $res->json('scope'));
        $this->assertSame($other->id, $res->json('tenant_id'));
    }

    public function test_tenant_admin_can_view_own_tenant(): void
    {
        $admin = $this->actingAsRole('tenant_admin');

        // 自テナントに 1件 customer を作成
        Customer::factory()->create(['tenant_id' => $admin->tenant_id]);

        $res = $this->getJson('/api/v1/admin/stats');

        $res->assertOk();
        $this->assertSame(1, $res->json('stats.customers.total'));
    }

    public function test_tenant_admin_cannot_view_other_tenant(): void
    {
        $this->actingAsRole('tenant_admin');
        $other = Tenant::factory()->create();

        $res = $this->getJson("/api/v1/admin/stats?tenant_id={$other->id}");

        $res->assertForbidden();
    }

    public function test_tenant_admin_cannot_use_all_scope(): void
    {
        $this->actingAsRole('tenant_admin');

        $res = $this->getJson('/api/v1/admin/stats?tenant_id=all');

        $res->assertForbidden();
    }

    public function test_tenant_user_is_forbidden(): void
    {
        $this->actingAsRole('tenant_user');

        $res = $this->getJson('/api/v1/admin/stats');

        $res->assertForbidden();
    }
}
