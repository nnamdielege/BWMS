<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CUST-####')),
            'name' => fake()->company().' Cafe',
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }
}
