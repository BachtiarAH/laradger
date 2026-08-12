<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tags = [
            ['name' => 'Priority', 'type' => 'priority'],
            ['name' => 'Recurring', 'type' => 'recurring'],
            ['name' => 'Vendor', 'type' => 'vendor'],
            ['name' => 'Taxable', 'type' => 'tax'],
            ['name' => 'Internal Transfer', 'type' => 'transfer'],
        ];

        foreach ($tags as $tagData) {
            Tag::create($tagData);
        }
    }
}
