<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    public function definition(): array
    {
        $startsAt = $this->faker->dateTimeBetween('-2 months', '+1 month');

        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'amount' => $this->faker->randomFloat(2, 100000, 10000000),
            'starts_at' => $startsAt->format('Y-m-d'),
            'ends_at' => (clone $startsAt)->modify('+'.random_int(1, 31).' days')->format('Y-m-d'),
        ];
    }
}
