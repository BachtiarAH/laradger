<?php

namespace Database\Factories;

use App\Models\Allocation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Allocation>
 */
class AllocationFactory extends Factory
{
    protected $model = Allocation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->optional()->sentence(),
            'target_amount' => $this->faker->optional()->randomFloat(2, 100000, 10000000),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
