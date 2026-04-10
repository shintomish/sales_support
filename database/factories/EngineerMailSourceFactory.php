<?php

namespace Database\Factories;

use App\Models\Email;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EngineerMailSource>
 */
class EngineerMailSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email_id'         => Email::factory(),
            'score'            => fake()->numberBetween(30, 90),
            'score_reasons'    => ['スキル記載あり', '稼働時期あり'],
            'engine'           => 'rule',
            'name'             => fake()->name(),
            'affiliation_type' => fake()->randomElement(['bp', 'self', 'freelance']),
            'available_from'   => '即日',
            'nearest_station'  => fake()->city() . '駅',
            'skills'           => ['PHP', 'Laravel', 'MySQL'],
            'has_attachment'   => false,
            'status'           => 'new',
            'received_at'      => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
