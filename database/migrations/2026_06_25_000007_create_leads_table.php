<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * leads — the operational heart of the CRM, designed for 50L+ rows.
 *
 * Indexing strategy:
 *  - dedup_hash (unique-ish lookup) for deduplication on mobile/email.
 *  - (assigned_to, status) for telecaller worklists.
 *  - (center_id, created_at) for center dashboards.
 *  - (stage_id) for kanban, (next_follow_up_at) for the reminder scheduler.
 * patient_id FK is added later (patients table created after leads).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();

            // Identity
            $table->string('name')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('alt_mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->unsignedTinyInteger('age')->nullable();

            // Location
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pincode', 10)->nullable();

            // Attribution
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('adset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained()->nullOnDelete();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('external_lead_id')->nullable()->comment('Provider lead id (FB leadgen etc.)');

            // Ownership / routing
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // State
            $table->foreignId('stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->foreignId('disposition_id')->nullable()->constrained('dispositions')->nullOnDelete();
            $table->foreignId('sub_disposition_id')->nullable()->constrained('dispositions')->nullOnDelete();
            $table->string('status', 30)->default('new')->comment('new, working, won, lost, junk');
            $table->unsignedBigInteger('patient_id')->nullable();

            // Engagement / scheduling
            $table->timestamp('lead_date')->nullable()->comment('Original lead generation time');
            $table->timestamp('first_contacted_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->unsignedInteger('contact_attempts')->default(0);

            // Scoring (AI-ready)
            $table->unsignedTinyInteger('score')->nullable()->comment('0-100 AI/rule lead score');
            $table->string('score_band', 20)->nullable()->comment('hot/warm/cold');

            $table->text('remark')->nullable();
            $table->json('extra')->nullable()->comment('Raw payload / custom fields');

            // Deduplication key (e.g. sha1 of normalized mobile)
            $table->string('dedup_hash', 64)->nullable();
            $table->foreignId('merged_into_id')->nullable()->comment('Surviving lead after merge');

            $table->timestamps();
            $table->softDeletes();

            // Indexes tuned for the heaviest query paths.
            $table->index('mobile');
            $table->index('email');
            $table->index('dedup_hash');
            $table->index('status');
            $table->index('next_follow_up_at');
            $table->index(['assigned_to', 'status']);
            $table->index(['center_id', 'created_at']);
            $table->index(['stage_id', 'center_id']);
            $table->index(['lead_source_id', 'created_at']);
            $table->index(['campaign_id', 'created_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
        });
        Schema::dropIfExists('leads');
    }
};
