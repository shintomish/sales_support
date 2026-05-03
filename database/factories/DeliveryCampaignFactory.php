<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DeliveryCampaign>
 */
class DeliveryCampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'send_type'     => 'delivery',
            'subject'       => fake()->sentence(4),
            'body'          => fake()->paragraph(),
            'total_count'   => 0,
            'success_count' => 0,
            'failed_count'  => 0,
            'sent_at'       => now(),
        ];
    }
}
