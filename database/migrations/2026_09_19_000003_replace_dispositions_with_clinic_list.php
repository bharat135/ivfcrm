<?php

use Database\Seeders\DispositionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the seeded disposition tree with the clinic's own flat list of
 * call outcomes. Old rows are deactivated, not deleted, so existing call logs
 * and leads keep pointing at a valid (now hidden) outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dispositions')) {
            return;
        }

        (new DispositionSeeder)->run();
    }

    public function down(): void
    {
        // Historical outcomes stay; nothing to restore safely.
    }
};
