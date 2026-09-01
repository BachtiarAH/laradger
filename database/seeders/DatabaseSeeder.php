<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );

        $tenant = $user->tenants()->first()
            ?? tap(
                Tenant::firstOrCreate(
                    ['slug' => 'test-company'],
                    ['name' => 'Test Company'],
                ),
                fn (Tenant $tenant) => $tenant->users()->attach($user, ['role' => 'owner']),
            );

        $bachtiar = User::firstOrCreate(
            ['email' => 'bachtiarah73@gmail.com'],
            ['name' => 'Bachtiar', 'password' => 'Password123'],
        );

        if (! $bachtiar->belongsToTenant($tenant)) {
            $tenant->users()->attach($bachtiar, ['role' => 'owner']);
        }

        TenantContext::set($tenant);

        try {
            $this->call([
                AccountSeeder::class,
                TagSeeder::class,
                JournalSeeder::class,
                JournalLineSeeder::class,
                JournalTagSeeder::class,
                AuditLogSeeder::class,
                BudgetSeeder::class,
            ]);
        } finally {
            TenantContext::forget();
        }
    }
}
