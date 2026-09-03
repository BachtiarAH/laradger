<?php

namespace Database\Seeders;

use App\Models\Journal;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class JournalTagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Mirrors the plan in JournalSeeder, tagging each journal by reference.
     */
    public function run(): void
    {
        $tagsFor = fn (string $key): array => match ($key) {
            'GAJI' => ['Rutin', 'Mendesak'],
            'TOPUP', 'BRI_TRF' => ['Antar Rekening'],
            'UTIL', 'UTIL_DRAFT' => ['Rutin'],
            'KES' => ['Mendesak'],
            'MAKAN2' => ['Online Shop'],
            default => [],
        };

        foreach ([0, 1, 2] as $index) {
            $month = now()->startOfMonth()->subMonths(2)->addMonths($index);
            $prefix = 'JRN-DEMO-'.$month->format('Ymd');

            $keys = $index === 0 ? ['OPEN', 'GAJI', 'MAKAN'] : ['GAJI', 'MAKAN'];

            if ($index < 2) {
                array_push($keys, 'TOPUP', 'MAKAN2', 'TRANSP', 'UTIL', 'BELANJA', 'BRI_TRF', 'HIBURAN', 'KES');
            } else {
                $keys[] = 'UTIL_DRAFT';
            }

            foreach ($keys as $key) {
                $journal = Journal::where('reference', $prefix.'-'.$key)->first();

                if (! $journal) {
                    continue;
                }

                $tagIds = Tag::whereIn('name', $tagsFor($key))->pluck('id');

                if ($tagIds->isNotEmpty()) {
                    $journal->tags()->syncWithoutDetaching($tagIds);
                }
            }
        }
    }
}
