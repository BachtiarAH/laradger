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
            ['name' => 'Priority', 'type' => 'priority'],
            ['name' => 'Recurring', 'type' => 'recurring'],
            ['name' => 'Vendor', 'type' => 'vendor'],
            ['name' => 'Taxable', 'type' => 'tax'],
            ['name' => 'Internal Transfer', 'type' => 'transfer'],
        ];

        foreach ($tags as $tagData) {
            Tag::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $tagData['name']],
                $tagData,
            );
        }
    }
}
