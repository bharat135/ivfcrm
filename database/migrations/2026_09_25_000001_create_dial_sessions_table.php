<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk calling: an agent picks leads and the CRM dials them one after another,
 * moving on as soon as each call's outcome is saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dial_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active')->index(); // active | done | stopped
            $table->json('lead_ids');
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedInteger('dialed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dial_sessions');
    }
};
