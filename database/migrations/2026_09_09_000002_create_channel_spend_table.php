<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily ad spend for channels other than Meta.
 *
 * Meta spend already lands in `meta_insights` from the Graph sync. Google Ads
 * needs somewhere equivalent, and so does anything the clinic buys that has no
 * API at all (a print campaign, a health fair). Without one row per channel per
 * day the CRM can show which channel produces more patients but never which one
 * produces them more cheaply — which is the only question that changes a budget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_spend', function (Blueprint $table) {
            $table->id();

            $table->string('channel', 30)->comment('AcquisitionChannel key, e.g. google_ads');
            $table->date('date');

            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();

            // The platform's own ids, so a re-sync updates rather than duplicates.
            $table->string('external_campaign_id', 64)->nullable();
            $table->string('external_campaign_name')->nullable();

            $table->decimal('spend', 12, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 12, 2)->default(0)
                ->comment('The platform’s own conversion count, for reconciliation');
            $table->string('currency', 5)->nullable();

            // Hand-entered rows have no platform to sync from, and must not be
            // overwritten by one.
            $table->boolean('is_manual')->default(false);
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // One row per campaign per day per channel.
            $table->unique(['channel', 'date', 'external_campaign_id'], 'channel_spend_unique');
            $table->index(['channel', 'date']);
            $table->index(['center_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_spend');
    }
};
