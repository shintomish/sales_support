<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Email>
 */
class EmailFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gmail_message_id' => fake()->unique()->uuid(),
            'subject'          => fake()->sentence(5),
            'from_address'     => fake()->safeEmail(),
            'from_name'        => fake()->name(),
            'to_address'       => fake()->safeEmail(),
            'body_text'        => fake()->paragraph(),
            'received_at'      => $received = fake()->dateTimeBetween('-30 days', 'now'),
            'arrived_at'       => $received,
            'is_read'          => false,
        ];
    }
}
