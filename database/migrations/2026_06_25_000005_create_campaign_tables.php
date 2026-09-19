<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead source + campaign attribution hierarchy:
 *   lead_sources -> campaigns -> adsets -> ads
 * Spend lives at campaign/adset/ad level to compute CPL/CPA/CPV/ROI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 80)->unique();
            // Channel family: facebook, instagram, google, website, whatsapp,
            // practo, justdial, indiamart, referral, walkin, camp, event, csv, api, other
            $table->string('channel', 40)->index();
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable()->comment('Webhook/integration config');
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('external_id')->nullable()->comment('Platform campaign id');
            $table->string('platform', 40)->nullable()->comment('meta, google, etc.');
            $table->string('objective')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->decimal('spend', 14, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['platform', 'external_id']);
        });

        Schema::create('adsets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('external_id')->nullable();
            $table->decimal('spend', 14, 2)->default(0);
            $table->timestamps();
            $table->index(['campaign_id', 'external_id']);
        });

        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adset_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('external_id')->nullable();
            $table->decimal('spend', 14, 2)->default(0);
            $table->timestamps();
            $table->index(['adset_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
        Schema::dropIfExists('adsets');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('lead_sources');
    }
};
