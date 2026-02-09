<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $tasks = [
            [
                'user_id' => 1,
                'customer_id' => 1,
                'deal_id' => 1,
                'title' => '見積書修正',
                'description' => '佐藤部長からの指摘事項を反映した見積書を作成する',
                'due_date' => now()->addDays(3),
                'status' => '進行中',
                'priority' => '高',
            ],
            [
                'user_id' => 1,
                'customer_id' => 2,
                'deal_id' => 2,
                'title' => '契約書準備',
                'description' => '契約書のドラフトを作成',
                'due_date' => now()->addWeek(),
                'status' => '未着手',
                'priority' => '中',
            ],
            [
                'user_id' => 1,
                'customer_id' => 3,
                'deal_id' => 3,
                'title' => '次回訪問日調整',
                'description' => '山田店長と次回訪問日の調整',
                'due_date' => now()->addDays(2),
                'status' => '未着手',
                'priority' => '低',
            ],
        ];

        foreach ($tasks as $task) {
            Task::create($task);
        }
    }
}