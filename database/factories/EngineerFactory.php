<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Engineer>
 */
class EngineerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'             => fake()->name(),
            'name_kana'        => null,
            'email'            => fake()->unique()->safeEmail(),
            'phone'            => '090-' . fake()->numerify('####-####'),
            'affiliation'      => fake()->company(),
            'affiliation_contact' => fake()->name(),
            'affiliation_email'   => fake()->safeEmail(),
            'age'              => fake()->numberBetween(25, 55),
            'gender'           => fake()->randomElement(['male', 'female', 'unanswered']),
            'nationality'      => '日本',
            'nearest_station'  => fake()->city() . '駅',
            'affiliation_type' => fake()->randomElement(['self', 'bp', 'freelance']),
        ];
    }
}
