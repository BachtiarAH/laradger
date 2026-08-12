<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalLine>
 */
class JournalLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_id' => Journal::inRandomOrder('id')->value('id') ?? Journal::factory(),
            'account_id' => Account::inRandomOrder('id')->value('id') ?? Account::factory(),
            'debit' => $this->faker->randomFloat(2, 0, 1000),
            'credit' => $this->faker->randomFloat(2, 0, 1000),
            'description' => $this->faker->sentence(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
