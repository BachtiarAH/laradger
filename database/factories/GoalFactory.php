<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'target_amount' => fake()->numberBetween(1000000, 50000000),
            'target_date' => fake()->dateTimeBetween('+1 month', '+2 years')->format('Y-m-d'),
            'recurring_contribution_amount' => fake()->numberBetween(100000, 2000000),
            'contribution_frequency' => 'monthly',
            'status' => 'active',
        ];
    }
}
