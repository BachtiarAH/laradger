<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenantId = TenantContext::id();

        $tags = [
            ['name' => 'Rutin', 'type' => 'recurring'],
            ['name' => 'Mendesak', 'type' => 'priority'],
            ['name' => 'Antar Rekening', 'type' => 'transfer'],
            ['name' => 'Pajak', 'type' => 'tax'],
            ['name' => 'Online Shop', 'type' => 'vendor'],
        ];

        foreach ($tags as $tagData) {
            Tag::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $tagData['name']],
                $tagData,
            );
        }
    }
}
