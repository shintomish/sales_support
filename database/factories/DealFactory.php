<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Deal>
 */
class DealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id'         => Customer::factory(),
            'user_id'             => User::factory(),
            'title'               => fake()->sentence(4),
            'status'              => fake()->randomElement(['新規', '提案', '交渉', '成約', '失注']),
            'amount'              => fake()->numberBetween(100000, 5000000),
            'probability'         => fake()->numberBetween(0, 100),
            'expected_close_date' => fake()->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'notes'               => null,
            'deal_type'           => 'general',
        ];
    }
}
