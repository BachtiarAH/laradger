<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_header')->default(false)->after('type');
        });

        // Backfill: accounts that already have children become headers.
        // This keeps existing hierarchies consistent after adding the flag.
        $parentIds = DB::table('accounts')
            ->whereNotNull('parent_id')
            ->distinct()
            ->pluck('parent_id');

        if ($parentIds->isNotEmpty()) {
            DB::table('accounts')->whereIn('id', $parentIds)->update(['is_header' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('is_header');
        });
    }
};
