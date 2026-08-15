<?php

namespace Database\Seeders;

use App\Models\Journal;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class JournalTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $journalTags = [
            'JRN-0001' => ['Priority'],
            'JRN-0002' => ['Vendor', 'Recurring'],
            'JRN-0003' => ['Priority', 'Taxable'],
            'JRN-0004' => ['Recurring', 'Priority'],
            'JRN-0005' => ['Taxable'],
        ];

        foreach ($journalTags as $reference => $tagNames) {
            $journal = Journal::where('reference', $reference)->first();

            if (! $journal) {
                continue;
            }

            $tagIds = Tag::whereIn('name', $tagNames)->pluck('id');

            $journal->tags()->syncWithoutDetaching($tagIds);
        }
    }
}
