<?php

namespace Database\Factories;

use App\Models\Journal;
use App\Models\JournalTag;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalTag>
 */
class JournalTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_id' => Journal::factory(),
            'tag_id' => Tag::factory(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
