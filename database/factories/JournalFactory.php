<?php

namespace Database\Factories;

use App\Models\Journal;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Journal>
 */
class JournalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'transaction_date' => $this->faker->date(),
            'description' => $this->faker->sentence(),
            'status' => $this->faker->randomElement(['draft', 'posted', 'archived']),
            'source' => $this->faker->randomElement(['manual', 'imported', 'system']),
            'reverse_from_id' => null,
            'reference' => $this->faker->uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
