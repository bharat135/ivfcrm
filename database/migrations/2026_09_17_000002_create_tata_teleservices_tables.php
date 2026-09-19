<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tata Tele Business Services (Smartflo) — two-way configuration sync.
 *
 * Smartflo is the system of record for agents, DIDs, departments and IVRs;
 * the CRM is the system of record for dispositions and leads. These tables
 * hold the mirror of each side plus the link between them, so a change on
 * either side can be pushed or pulled without guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Smartflo users/agents mirrored locally and linked to CRM users.
        Schema::create('tata_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('smartflo_user_id', 40)->nullable()->index()->comment('Smartflo user id');
            $table->string('agent_id', 40)->nullable()->index()->comment('Smartflo agent id (050…)');
            $table->string('name')->nullable();
            $table->string('login_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('follow_me_number', 30)->nullable()->index()->comment('Mobile Smartflo rings for the agent');
            $table->string('extension', 30)->nullable()->comment('Smartflo extension (060…)');
            $table->boolean('is_login_based_calling')->default(false);
            $table->boolean('is_web_login')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('sync_status', 20)->default('unlinked')->comment('linked, unlinked, pending, error');
            $table->string('sync_direction', 20)->nullable()->comment('pulled, pushed');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        // CRM dispositions ↔ Smartflo dialer disposition statuses.
        Schema::create('tata_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disposition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('smartflo_list_id', 40)->nullable()->index();
            $table->string('smartflo_status_id', 40)->nullable()->index();
            $table->string('smartflo_parent_id', 40)->nullable();
            $table->string('name');
            $table->string('code', 100)->nullable()->index();
            $table->string('sync_status', 20)->default('pending')->comment('synced, pending, error');
            $table->string('sync_direction', 20)->nullable()->comment('pulled, pushed');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->unique(['smartflo_list_id', 'code']);
        });

        // DIDs on the Smartflo account, with the CRM's own caller-id preferences.
        Schema::create('tata_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('smartflo_id', 40)->nullable()->unique();
            $table->string('did', 30)->index();
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->string('alias')->nullable();
            $table->string('destination', 60)->nullable();
            $table->string('destination_name')->nullable();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete()
                ->comment('Use this DID as caller id for agents of this center');
            $table->boolean('is_default')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('tata_departments', function (Blueprint $table) {
            $table->id();
            $table->string('smartflo_id', 40)->unique();
            $table->string('name');
            $table->string('ring_strategy', 40)->nullable();
            $table->unsignedSmallInteger('agent_count')->default(0);
            $table->json('agents')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('tata_ivrs', function (Blueprint $table) {
            $table->id();
            $table->string('smartflo_id', 40)->unique();
            $table->string('name');
            $table->json('menu')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        // Voice broadcasts built from CRM lead segments and run on Smartflo.
        Schema::create('tata_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('smartflo_id', 40)->nullable()->index();
            $table->string('list_id', 40)->nullable()->comment('Smartflo lead list id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('status', 20)->default('draft')
                ->comment('draft, created, running, paused, ended, failed');
            $table->string('destination', 80)->nullable()->comment('ivr|id, department|id, agent|id');
            $table->string('caller_id', 30)->nullable();
            $table->json('filters')->nullable()->comment('Lead segment used to build the list');
            $table->json('options')->nullable()->comment('timeout, retries, concurrency, time_group…');
            $table->unsignedInteger('lead_count')->default(0);
            $table->json('batch_ids')->nullable();
            $table->json('stats')->nullable();
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('tata_broadcast_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tata_broadcast_id')->constrained('tata_broadcasts')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobile', 20)->index();
            $table->string('status', 20)->default('queued')->comment('queued, dialed, answered, missed, failed');
            $table->string('dial_status', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('call_log_id')->nullable()->constrained()->nullOnDelete();
            $table->json('last_event')->nullable();
            $table->timestamps();
            $table->unique(['tata_broadcast_id', 'mobile']);
        });

        // Live transcript + one-line AI summary per call.
        Schema::create('call_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_log_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('browser')->comment('browser (Web Speech), server');
            $table->string('language', 12)->nullable();
            $table->json('segments')->nullable()->comment('[{t, speaker, text, final}]');
            $table->longText('transcript')->nullable();
            $table->string('summary', 255)->nullable();
            $table->string('summary_source', 20)->nullable()->comment('ai, heuristic, manual');
            $table->string('status', 12)->default('live')->comment('live, final');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // Outcome push-back to Smartflo (note + disposition on the Smartflo call).
        Schema::table('call_logs', function (Blueprint $table) {
            $table->timestamp('tata_synced_at')->nullable()->after('meta');
            $table->string('tata_sync_error', 500)->nullable()->after('tata_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['tata_synced_at', 'tata_sync_error']);
        });

        Schema::dropIfExists('call_transcripts');
        Schema::dropIfExists('tata_broadcast_leads');
        Schema::dropIfExists('tata_broadcasts');
        Schema::dropIfExists('tata_ivrs');
        Schema::dropIfExists('tata_departments');
        Schema::dropIfExists('tata_numbers');
        Schema::dropIfExists('tata_dispositions');
        Schema::dropIfExists('tata_agents');
    }
};
