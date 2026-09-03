<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalTemplateLine>
 */
class JournalTemplateLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_template_id' => JournalTemplate::inRandomOrder('id')->value('id') ?? JournalTemplate::factory(),
            'account_id' => Account::inRandomOrder('id')->value('id') ?? Account::factory(),
            'line_number' => 1,
            'debit' => $this->faker->randomFloat(2, 0, 1000),
            'credit' => $this->faker->randomFloat(2, 0, 1000),
            'description' => $this->faker->sentence(),
        ];
    }
}
