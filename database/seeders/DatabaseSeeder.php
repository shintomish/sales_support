<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // テストユーザー作成
        User::factory()->create([
            'name' => 'テスト営業',
            'email' => 'sales@gmail.com',
            'password' => bcrypt('password'),
        ]);

        // 各シーダー実行
        $this->call([
            CustomerSeeder::class,
            ContactSeeder::class,
            DealSeeder::class,
            ActivitySeeder::class,
            TaskSeeder::class,
        ]);
    }
}