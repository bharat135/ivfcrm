<?php

use Database\Seeders\CenterSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The clinic's real centres (Ulwe, Akola, Shirur, Thane) replace the two
 * placeholder centres the app shipped with. Records that pointed at a
 * placeholder move to Ulwe; the placeholders are deactivated, not deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('centers')) {
            return;
        }

        (new CenterSeeder)->run();
    }

    public function down(): void
    {
        // Centres are reference data; nothing to undo safely.
    }
};
