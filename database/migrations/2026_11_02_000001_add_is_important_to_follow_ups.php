<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent can flag a follow-up as important when dispositioning a call, so
 * the Follow-Ups worklist can surface and filter the ones that must not slip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->boolean('is_important')->default(false)->after('status');
            $table->index(['is_important', 'status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex(['is_important', 'status', 'scheduled_at']);
            $table->dropColumn('is_important');
        });
    }
};
