<?php

namespace Database\Seeders;

use App\Models\Activity;
use Illuminate\Database\Seeder;

class ActivitySeeder extends Seeder
{
    public function run(): void
    {
        $activities = [
            [
                'customer_id' => 1,
                'deal_id' => 1,
                'contact_id' => 1,
                'user_id' => 1,
                'type' => '訪問',
                'subject' => '新システム提案',
                'content' => '新システムの概要を説明。好感触を得た。',
                'activity_date' => now()->subDays(5),
            ],
            [
                'customer_id' => 1,
                'deal_id' => 1,
                'contact_id' => 2,
                'user_id' => 1,
                'type' => '電話',
                'subject' => '見積もり確認',
                'content' => '見積もり内容について質問があり回答した。',
                'activity_date' => now()->subDays(2),
            ],
            [
                'customer_id' => 2,
                'deal_id' => 2,
                'contact_id' => 3,
                'user_id' => 1,
                'type' => 'メール',
                'subject' => '価格交渉',
                'content' => '価格見直しの提案を送付。',
                'activity_date' => now()->subDay(),
            ],
        ];

        foreach ($activities as $activity) {
            Activity::create($activity);
        }
    }
}