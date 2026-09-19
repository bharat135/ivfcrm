<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Lead Assignment page shows how many leads each rule has routed, so a
 * manager can see which rules actually fire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->unsignedInteger('leads_count')->default(0)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->dropColumn('leads_count');
        });
    }
};
