<?php

namespace Database\Factories;

use App\Models\Email;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectMailSource>
 */
class ProjectMailSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email_id'      => Email::factory(),
            'score'         => fake()->numberBetween(40, 90),
            'score_reasons' => ['スキル要件記載あり'],
            'engine'        => 'rule',
            'title'         => fake()->sentence(4) . '案件',
            'customer_name' => fake()->company(),
            'sales_contact' => fake()->name(),
            'work_location' => '東京都千代田区',
            'remote_ok'     => true,
            'status'        => 'new',
            'received_at'   => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
