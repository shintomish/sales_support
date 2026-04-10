<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_name' => fake()->unique()->company(),
            'industry'     => fake()->randomElement(['IT', '製造', '金融', '商社', '医療']),
            'phone'        => '03-' . fake()->numerify('####-####'),
            'address'      => '東京都千代田区' . fake()->numberBetween(1, 9) . '-' . fake()->numberBetween(1, 20),
            'employee_count' => fake()->numberBetween(10, 5000),
            'website'      => fake()->url(),
            'notes'        => null,
        ];
    }
}
