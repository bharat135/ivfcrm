<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting system tables: key/value settings, activity/audit log,
 * per-user saved DataTable filters, and a raw inbound webhook event log
 * (lead-source captures, telephony callbacks, channel webhooks).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group', 60)->default('general');
            $table->string('key');
            $table->longText('value')->nullable();
            $table->string('type', 20)->default('string')->comment('string, bool, int, json');
            $table->timestamps();
            $table->unique(['center_id', 'group', 'key']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60)->index();
            $table->nullableMorphs('subject');
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['action', 'created_at']);
        });

        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 60)->index()->comment('leads, calls, appointments...');
            $table->string('name');
            $table->json('payload');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 60)->index()->comment('meta, google, wati, exotel, website...');
            $table->string('event_type', 80)->nullable();
            $table->string('external_id')->nullable()->index();
            $table->json('payload');
            $table->string('status', 20)->default('received')->comment('received, processed, failed, ignored');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('saved_filters');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('settings');
    }
};
