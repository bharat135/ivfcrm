<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smartflo call records carry values like "Extension-0608031380004" (23
 * chars) in the agent field and E.164 numbers with a plus sign, which did not
 * fit the original 20-character columns and made "Pull call records" 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('extension', 64)->nullable()->change();
            $table->string('from_number', 32)->nullable()->change();
            $table->string('to_number', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->string('extension', 20)->nullable()->change();
            $table->string('from_number', 20)->nullable()->change();
            $table->string('to_number', 20)->nullable()->change();
        });
    }
};
