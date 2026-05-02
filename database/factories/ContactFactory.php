<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name'        => fake()->name(),
            'department'  => fake()->randomElement(['営業部', '開発部', '人事部', '経理部']),
            'position'    => fake()->randomElement(['部長', '課長', '主任', '担当']),
            'email'       => fake()->unique()->safeEmail(),
            'phone'       => '03-' . fake()->numerify('####-####'),
            'notes'       => null,
        ];
    }
}
