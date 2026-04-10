<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PublicProject>
 */
class PublicProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'posted_by_user_id' => User::factory(),
            'title'             => fake()->sentence(3) . 'エンジニア募集',
            'description'    => fake()->paragraph(),
            'unit_price_min' => 60,
            'unit_price_max' => 80,
            'work_style'     => fake()->randomElement(['remote', 'office', 'hybrid']),
            'work_location'  => '東京都港区',
            'start_date'     => now()->addMonth()->format('Y-m-d'),
            'status'         => 'open',
            'published_at'   => now()->subDay(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status'       => 'open',
            'published_at' => now()->subDay(),
        ]);
    }
}
