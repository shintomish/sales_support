<?php

namespace Tests;

use App\Http\Middleware\SupabaseAuth;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** 現在のテストで認証中のユーザー */
    protected User $authUser;

    /**
     * テナントとユーザーを作成し、SupabaseAuth ミドルウェアをバイパスして認証状態にする。
     * $this->authUser で認証ユーザーにアクセスできる。
     */
    protected function actingAsUser(array $userAttrs = []): static
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->tenantUser($tenant)->create($userAttrs);

        $this->authUser = $user;
        $this->actingAs($user);
        $this->withoutMiddleware(SupabaseAuth::class);

        return $this;
    }

    /**
     * ilike など PostgreSQL 固有の演算子を使うテストは SQLite では実行できないためスキップ。
     */
    protected function skipIfSqlite(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('This test requires PostgreSQL (ilike / Postgres-specific syntax).');
        }
    }

    /**
     * 別テナントのユーザーを作成する（テナント分離テスト用）。
     */
    protected function createUserInAnotherTenant(): User
    {
        $tenant = Tenant::factory()->create();
        return User::factory()->tenantUser($tenant)->create();
    }
}
