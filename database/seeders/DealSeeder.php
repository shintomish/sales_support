<?php

namespace Database\Seeders;

use App\Models\Deal;
use Illuminate\Database\Seeder;

class DealSeeder extends Seeder
{
    public function run(): void
    {
        $deals = [
            [
                'customer_id' => 1,
                'contact_id' => 1,
                'user_id' => 1,
                'title' => '新システム導入案件',
                'amount' => 5000000,
                'status' => '提案',
                'probability' => 60,
                'expected_close_date' => now()->addMonths(2),
                'notes' => '来期の予算で検討中',
            ],
            [
                'customer_id' => 2,
                'contact_id' => 3,
                'user_id' => 1,
                'title' => 'クラウドサービス導入',
                'amount' => 3000000,
                'status' => '交渉',
                'probability' => 80,
                'expected_close_date' => now()->addMonth(),
                'notes' => '価格交渉中',
            ],
            [
                'customer_id' => 3,
                'contact_id' => 4,
                'user_id' => 1,
                'title' => 'POSシステム更新',
                'amount' => 1500000,
                'status' => '新規',
                'probability' => 30,
                'expected_close_date' => now()->addMonths(3),
                'notes' => '初回ヒアリング完了',
            ],
        ];

        foreach ($deals as $deal) {
            Deal::create($deal);
        }
    }
}