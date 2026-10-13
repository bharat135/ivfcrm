<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-touch attribution: a person can enquire more than once, and the second
 * time may come from a different channel (first Meta, later Google). The lead's
 * own columns hold the FIRST touch; every touch (including the first) is also
 * recorded here, so the full source/campaign journey is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_source_touches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30)->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('adset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_id')->nullable()->constrained()->nullOnDelete();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('external_lead_id')->nullable();
            $table->boolean('is_first')->default(false);
            $table->timestamp('occurred_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'occurred_at']);
            $table->index(['channel', 'occurred_at']);
        });

        // Seed the first touch for every existing lead from its own columns, so
        // history is complete from day one rather than only for new enquiries.
        \Illuminate\Support\Facades\DB::statement("
            INSERT INTO lead_source_touches
                (lead_id, channel, lead_source_id, campaign_id, adset_id, ad_id, utm_source, utm_medium, utm_campaign, external_lead_id, is_first, occurred_at, created_at, updated_at)
            SELECT id, channel, lead_source_id, campaign_id, adset_id, ad_id, utm_source, utm_medium, utm_campaign, external_lead_id, 1,
                   COALESCE(lead_date, created_at, NOW()), NOW(), NOW()
            FROM leads
            WHERE deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_source_touches');
    }
};
