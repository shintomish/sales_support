<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SupabaseAuthAdminService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #14: テナント別ユーザー管理 (CRUD) 認可テスト
 *
 * Supabase Auth Admin API は SupabaseAuthAdminService をモック化して隔離する。
 */
class UserCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(SupabaseAuthAdminService::class, function ($mock) {
            $mock->shouldReceive('inviteUser')->andReturnUsing(fn() => 'mock-uuid-' . Str::random(8));
            $mock->shouldReceive('updateUser')->andReturn(null);
            $mock->shouldReceive('deleteUser')->andReturn(null);
            $mock->shouldReceive('sendRecovery')->andReturn(null);
        });
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

    // ─── store ───

    public function test_super_admin_can_create_user_in_any_tenant(): void
    {
        $this->actingAsRole('super_admin');
        $otherTenant = Tenant::factory()->create();

        $res = $this->postJson('/api/v1/users', [
            'name'      => 'New User',
            'email'     => 'new@example.com',
            'role'      => 'tenant_user',
            'tenant_id' => $otherTenant->id,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'tenant_id' => $otherTenant->id]);
    }

    public function test_tenant_admin_can_create_user_in_own_tenant(): void
    {
        $admin = $this->actingAsRole('tenant_admin');

        $res = $this->postJson('/api/v1/users', [
            'name'  => 'Member',
            'email' => 'member@example.com',
            'role'  => 'tenant_user',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'member@example.com', 'tenant_id' => $admin->tenant_id]);
    }

    public function test_tenant_admin_cannot_create_user_in_other_tenant(): void
    {
        $this->actingAsRole('tenant_admin');
        $otherTenant = Tenant::factory()->create();

        $res = $this->postJson('/api/v1/users', [
            'name'      => 'Cross',
            'email'     => 'cross@example.com',
            'role'      => 'tenant_user',
            'tenant_id' => $otherTenant->id,
        ]);

        $res->assertForbidden();
    }

    public function test_tenant_admin_cannot_create_super_admin(): void
    {
        $this->actingAsRole('tenant_admin');

        $res = $this->postJson('/api/v1/users', [
            'name'  => 'Boss',
            'email' => 'boss@example.com',
            'role'  => 'super_admin',
        ]);

        $res->assertForbidden();
    }

    public function test_tenant_user_cannot_create_user(): void
    {
        $this->actingAsRole('tenant_user');

        $res = $this->postJson('/api/v1/users', [
            'name'  => 'X',
            'email' => 'x@example.com',
            'role'  => 'tenant_user',
        ]);

        $res->assertForbidden();
    }

    // ─── update ───

    public function test_self_role_change_is_forbidden(): void
    {
        $admin = $this->actingAsRole('tenant_admin');

        $res = $this->putJson("/api/v1/users/{$admin->id}", ['role' => 'tenant_user']);

        $res->assertForbidden();
    }

    public function test_tenant_admin_cannot_promote_to_super_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsRole('tenant_admin', $tenant);
        $member = User::factory()->tenantUser($tenant)->create();

        $res = $this->putJson("/api/v1/users/{$member->id}", ['role' => 'super_admin']);

        $res->assertForbidden();
    }

    public function test_tenant_admin_can_update_member_in_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsRole('tenant_admin', $tenant);
        $member = User::factory()->tenantUser($tenant)->create();

        $res = $this->putJson("/api/v1/users/{$member->id}", ['name' => 'Renamed']);

        $res->assertOk();
        $this->assertDatabaseHas('users', ['id' => $member->id, 'name' => 'Renamed']);
    }

    public function test_tenant_admin_cannot_update_user_in_other_tenant(): void
    {
        $this->actingAsRole('tenant_admin');
        $other = User::factory()->tenantUser(Tenant::factory()->create())->create();

        $res = $this->putJson("/api/v1/users/{$other->id}", ['name' => 'X']);

        $res->assertForbidden();
    }

    // ─── destroy ───

    public function test_self_delete_is_forbidden(): void
    {
        $admin = $this->actingAsRole('tenant_admin');

        $res = $this->deleteJson("/api/v1/users/{$admin->id}");

        $res->assertForbidden();
    }

    public function test_tenant_admin_can_delete_member_in_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsRole('tenant_admin', $tenant);
        $member = User::factory()->tenantUser($tenant)->create(['supabase_uid' => Str::uuid()->toString()]);

        $res = $this->deleteJson("/api/v1/users/{$member->id}");

        $res->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    public function test_tenant_admin_cannot_delete_super_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsRole('tenant_admin', $tenant);
        $sa = User::factory()->superAdmin($tenant)->create();

        $res = $this->deleteJson("/api/v1/users/{$sa->id}");

        $res->assertForbidden();
    }

    // ─── resendInvite ───

    public function test_resend_invite_works_for_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsRole('tenant_admin', $tenant);
        $member = User::factory()->tenantUser($tenant)->create();

        $res = $this->postJson("/api/v1/users/{$member->id}/resend-invite");

        $res->assertOk();
    }

    public function test_resend_invite_forbidden_for_other_tenant(): void
    {
        $this->actingAsRole('tenant_admin');
        $other = User::factory()->tenantUser(Tenant::factory()->create())->create();

        $res = $this->postJson("/api/v1/users/{$other->id}/resend-invite");

        $res->assertForbidden();
    }
}
