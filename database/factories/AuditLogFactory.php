<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => $this->faker->word(),
            'before' => null,
            'after' => null,
            'reason' => $this->faker->sentence(),
            'journal_id' => Journal::factory(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
