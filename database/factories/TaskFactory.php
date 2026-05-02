<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'customer_id' => Customer::factory(),
            'deal_id'     => null,
            'title'       => fake()->randomElement(['提案資料作成', 'フォローアップ', '見積送付', 'ミーティング設定']),
            'description' => null,
            'due_date'    => now()->addDays(fake()->numberBetween(1, 14))->format('Y-m-d'),
            'status'      => fake()->randomElement(['未着手', '進行中', '完了']),
            'priority'    => fake()->randomElement(['低', '中', '高']),
        ];
    }
}
