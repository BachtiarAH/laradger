<?php

namespace Database\Factories;

use App\Models\JournalTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalTemplate>
 */
class JournalTemplateFactory extends Factory
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
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->sentence(),
            'period_type' => $this->faker->randomElement(['daily', 'weekly', 'monthly']),
            'is_active' => true,
            'day_of_week' => null,
            'day_of_month' => null,
            'next_run_at' => now()->addDay(),
            'last_run_at' => null,
        ];
    }

    public function daily(): static
    {
        return $this->state(fn () => ['period_type' => 'daily']);
    }

    public function weekly(int $dayOfWeek = 1): static
    {
        return $this->state(fn () => [
            'period_type' => 'weekly',
            'day_of_week' => $dayOfWeek,
        ]);
    }

    public function monthly(int $dayOfMonth = 1): static
    {
        return $this->state(fn () => [
            'period_type' => 'monthly',
            'day_of_month' => $dayOfMonth,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
