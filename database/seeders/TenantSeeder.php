<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Tenant;
use App\Models\User;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // テナントA
        $tenantA = Tenant::create([
            'name'      => '株式会社サンプル商事',
            'slug'      => 'sample',
            'plan'      => 'pro',
            'is_active' => true,
        ]);

        // テナントAの管理者
        User::create([
            'tenant_id' => $tenantA->id,
            'name'      => 'サンプル管理者',
            'email'     => 'admin@sample.com',
            'password'  => Hash::make('password'),
            'role'      => 'tenant_admin',
        ]);

        // テナントAの一般ユーザー
        User::create([
            'tenant_id' => $tenantA->id,
            'name'      => 'サンプルユーザーA',
            'email'     => 'usera@sample.com',
            'password'  => Hash::make('password'),
            'role'      => 'tenant_user',
        ]);

        User::create([
            'tenant_id' => $tenantA->id,
            'name'      => 'サンプルユーザーB',
            'email'     => 'userb@sample.com',
            'password'  => Hash::make('password'),
            'role'      => 'tenant_user',
        ]);

        // テナントB
        $tenantB = Tenant::create([
            'name'      => 'テストシステムズ株式会社',
            'slug'      => 'test-systems',
            'plan'      => 'basic',
            'is_active' => true,
        ]);

        // テナントBの管理者
        User::create([
            'tenant_id' => $tenantB->id,
            'name'      => 'テスト管理者',
            'email'     => 'admin@test-systems.com',
            'password'  => Hash::make('password'),
            'role'      => 'tenant_admin',
        ]);

        // super_admin（テナントなし）
        User::create([
            'tenant_id' => null,
            'name'      => 'スーパー管理者',
            'email'     => 'super@admin.com',
            'password'  => Hash::make('password'),
            'role'      => 'super_admin',
        ]);
    }
}
