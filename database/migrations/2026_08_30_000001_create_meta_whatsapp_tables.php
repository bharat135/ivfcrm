<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta / WhatsApp Business Platform integration.
 *
 * Adds the WhatsApp Cloud API layer on top of the existing omnichannel tables
 * (channel_accounts -> conversations -> messages -> message_attachments) and the
 * Meta Marketing API layer on top of the existing campaigns -> adsets -> ads tree.
 *
 * Design notes:
 *  - Credentials never live here; they live in `settings` (encrypted) and in
 *    channel_accounts.credentials (encrypted:array cast).
 *  - Every Meta-owned row keeps `external_id` so a re-sync is idempotent.
 *  - `meta_insights` is a daily fact table keyed by (level, external_id, date)
 *    so analytics never has to hit the Graph API at render time.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // WhatsApp: extend the omnichannel tables
        // ---------------------------------------------------------------
        Schema::table('conversations', function (Blueprint $table) {
            // WhatsApp 24h customer-service window. Outside it, only templates send.
            $table->timestamp('last_inbound_at')->nullable()->after('last_message_at');
            $table->timestamp('window_expires_at')->nullable()->after('last_inbound_at');
            $table->string('contact_name')->nullable()->after('contact_identifier');
            $table->string('wa_id', 30)->nullable()->after('contact_name')->comment('WhatsApp id returned by Meta');
            $table->index('wa_id');
            $table->index(['channel', 'contact_identifier']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('media_type', 20)->nullable()->after('type')
                ->comment('image, video, audio, document, sticker, location, contacts, button, interactive');
            $table->string('template_name')->nullable()->after('media_type');
            $table->string('reply_to_message_id')->nullable()->after('provider_message_id');
            $table->string('error_code', 40)->nullable()->after('status');
            $table->text('error_message')->nullable()->after('error_code');
            $table->timestamp('sent_at')->nullable()->after('error_message');
            $table->timestamp('failed_at')->nullable()->after('delivered_at');
            $table->index(['direction', 'status']);
        });

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->string('disk', 30)->default('local')->after('path');
            $table->string('provider_media_id')->nullable()->after('disk')->index();
            $table->string('type', 20)->nullable()->after('mime')->comment('image, video, audio, document, sticker');
            $table->text('caption')->nullable()->after('filename');
            $table->string('sha256', 100)->nullable()->after('size');
            $table->string('download_status', 20)->default('pending')->after('sha256')
                ->comment('pending, downloaded, failed, na');
        });

        // ---------------------------------------------------------------
        // WhatsApp message templates (mirror of the WABA template library)
        // ---------------------------------------------------------------
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('language', 20)->default('en');
            $table->string('category', 40)->default('UTILITY')->comment('MARKETING, UTILITY, AUTHENTICATION');
            $table->string('status', 30)->default('PENDING')
                ->comment('APPROVED, PENDING, REJECTED, PAUSED, DISABLED');
            $table->string('external_id')->nullable()->index();
            $table->json('components')->nullable()->comment('Raw Meta component array');
            $table->text('body_text')->nullable()->comment('Flattened body for preview/search');
            $table->unsignedTinyInteger('variable_count')->default(0);
            $table->string('header_format', 20)->nullable()->comment('TEXT, IMAGE, VIDEO, DOCUMENT');
            $table->text('rejected_reason')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['channel_account_id', 'name', 'language'], 'wa_tpl_unique');
            $table->index(['status', 'category']);
        });

        // ---------------------------------------------------------------
        // Broadcasts (bulk template sends to a filtered audience)
        // ---------------------------------------------------------------
        Schema::create('whatsapp_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience_type', 20)->default('leads')->comment('leads, patients, manual');
            $table->json('filters')->nullable();
            $table->json('variable_map')->nullable()->comment('Template var index -> lead attribute or static text');
            $table->string('status', 20)->default('draft')
                ->comment('draft, scheduled, running, completed, failed, cancelled');
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('whatsapp_broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobile', 20);
            $table->string('name')->nullable();
            $table->json('variables')->nullable();
            $table->string('status', 20)->default('pending')
                ->comment('pending, sent, delivered, read, failed, skipped');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['whatsapp_broadcast_id', 'status'], 'wa_bcast_recipient_status');
        });

        // ---------------------------------------------------------------
        // Meta Marketing API: extend campaigns / adsets / ads
        // ---------------------------------------------------------------
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('external_account_id', 60)->nullable()->after('external_id')->comment('act_XXXX');
            $table->string('status', 30)->default('PAUSED')->after('objective')
                ->comment('ACTIVE, PAUSED, DELETED, ARCHIVED');
            $table->string('effective_status', 30)->nullable()->after('status');
            $table->string('buying_type', 30)->nullable()->after('effective_status');
            $table->decimal('daily_budget', 14, 2)->nullable()->after('budget');
            $table->decimal('lifetime_budget', 14, 2)->nullable()->after('daily_budget');
            $table->string('bid_strategy', 40)->nullable()->after('lifetime_budget');
            $table->json('special_ad_categories')->nullable()->after('bid_strategy');
            $table->timestamp('synced_at')->nullable()->after('is_active');
            $table->text('sync_error')->nullable()->after('synced_at');
        });

        Schema::table('adsets', function (Blueprint $table) {
            $table->string('status', 30)->default('PAUSED')->after('external_id');
            $table->string('effective_status', 30)->nullable()->after('status');
            $table->decimal('daily_budget', 14, 2)->nullable()->after('effective_status');
            $table->decimal('lifetime_budget', 14, 2)->nullable()->after('daily_budget');
            $table->string('optimization_goal', 60)->nullable()->after('lifetime_budget');
            $table->string('billing_event', 60)->nullable()->after('optimization_goal');
            $table->decimal('bid_amount', 14, 2)->nullable()->after('billing_event');
            $table->json('targeting')->nullable()->after('bid_amount');
            $table->json('promoted_object')->nullable()->after('targeting');
            $table->string('destination_type', 40)->nullable()->after('promoted_object');
            $table->dateTime('start_time')->nullable()->after('destination_type');
            $table->dateTime('end_time')->nullable()->after('start_time');
            $table->timestamp('synced_at')->nullable()->after('spend');
            $table->text('sync_error')->nullable()->after('synced_at');
        });

        Schema::table('ads', function (Blueprint $table) {
            $table->string('status', 30)->default('PAUSED')->after('external_id');
            $table->string('effective_status', 30)->nullable()->after('status');
            $table->string('external_creative_id')->nullable()->after('effective_status');
            $table->json('creative')->nullable()->after('external_creative_id');
            $table->text('preview_url')->nullable()->after('creative');
            $table->text('thumbnail_url')->nullable()->after('preview_url');
            $table->unsignedBigInteger('meta_lead_form_id')->nullable()->after('thumbnail_url');
            $table->timestamp('synced_at')->nullable()->after('spend');
            $table->text('sync_error')->nullable()->after('synced_at');
        });

        // ---------------------------------------------------------------
        // Lead-gen forms
        // ---------------------------------------------------------------
        Schema::create('meta_lead_forms', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 60)->unique();
            $table->string('page_id', 60)->nullable()->index();
            $table->string('page_name')->nullable();
            $table->string('name');
            $table->string('status', 30)->default('ACTIVE')->comment('ACTIVE, ARCHIVED, DRAFT');
            $table->string('locale', 20)->nullable();
            $table->json('questions')->nullable();
            $table->json('field_map')->nullable()->comment('Meta field key -> CRM lead attribute');
            $table->text('privacy_policy_url')->nullable();
            $table->text('follow_up_action_url')->nullable();
            // Routing defaults applied to every lead captured through this form.
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('auto_sync')->default(true);
            $table->unsignedInteger('leads_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // ---------------------------------------------------------------
        // Daily insights fact table
        // ---------------------------------------------------------------
        Schema::create('meta_insights', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20)->comment('account, campaign, adset, ad');
            $table->string('external_id', 60)->index();
            $table->unsignedBigInteger('entity_id')->nullable()->comment('Local campaigns/adsets/ads id');
            $table->date('date');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('unique_clicks')->default(0);
            $table->decimal('spend', 14, 2)->default(0);
            $table->decimal('frequency', 10, 4)->default(0);
            $table->decimal('ctr', 10, 4)->default(0);
            $table->decimal('cpc', 14, 4)->default(0);
            $table->decimal('cpm', 14, 4)->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->decimal('cost_per_lead', 14, 4)->default(0);
            $table->unsignedInteger('messaging_conversations')->default(0);
            $table->unsignedInteger('landing_page_views')->default(0);
            $table->json('actions')->nullable();
            $table->timestamps();
            $table->unique(['level', 'external_id', 'date'], 'meta_insights_unique');
            $table->index(['level', 'date']);
            $table->index(['entity_id', 'date']);
        });

        // ---------------------------------------------------------------
        // Landing pages
        // ---------------------------------------------------------------
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 120)->unique();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('headline')->nullable();
            $table->text('subheadline')->nullable();
            $table->string('hero_image')->nullable();
            $table->string('logo_image')->nullable();
            $table->string('template', 40)->default('clinic')->comment('clinic, minimal, longform');
            $table->string('theme_color', 20)->default('#4361ee');
            $table->longText('body_html')->nullable();
            $table->longText('custom_css')->nullable();
            $table->json('sections')->nullable()->comment('USPs, stats, testimonials, FAQ');
            $table->json('form_fields')->nullable();
            $table->string('cta_text')->default('Book Free Consultation');
            $table->text('thank_you_message')->nullable();
            $table->text('redirect_url')->nullable();
            $table->string('whatsapp_number', 20)->nullable();
            // Attribution + routing for captured leads
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('whatsapp_template_id')->nullable()->constrained()->nullOnDelete();
            // Tracking
            $table->string('meta_pixel_id', 60)->nullable();
            $table->string('google_analytics_id', 60)->nullable();
            $table->json('seo')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('submissions')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_published', 'slug']);
        });

        Schema::create('landing_page_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->json('utm')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('referrer')->nullable();
            $table->timestamps();
            $table->index(['landing_page_id', 'created_at']);
        });

        Schema::table('ads', function (Blueprint $table) {
            $table->foreign('meta_lead_form_id')->references('id')->on('meta_lead_forms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ads', function (Blueprint $table) {
            $table->dropForeign(['meta_lead_form_id']);
        });

        Schema::dropIfExists('landing_page_submissions');
        Schema::dropIfExists('landing_pages');
        Schema::dropIfExists('meta_insights');
        Schema::dropIfExists('meta_lead_forms');
        Schema::dropIfExists('whatsapp_broadcast_recipients');
        Schema::dropIfExists('whatsapp_broadcasts');
        Schema::dropIfExists('whatsapp_templates');

        Schema::table('ads', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'effective_status', 'external_creative_id', 'creative',
                'preview_url', 'thumbnail_url', 'meta_lead_form_id', 'synced_at', 'sync_error',
            ]);
        });

        Schema::table('adsets', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'effective_status', 'daily_budget', 'lifetime_budget',
                'optimization_goal', 'billing_event', 'bid_amount', 'targeting',
                'promoted_object', 'destination_type', 'start_time', 'end_time',
                'synced_at', 'sync_error',
            ]);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'external_account_id', 'status', 'effective_status', 'buying_type',
                'daily_budget', 'lifetime_budget', 'bid_strategy', 'special_ad_categories',
                'synced_at', 'sync_error',
            ]);
        });

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn(['disk', 'provider_media_id', 'type', 'caption', 'sha256', 'download_status']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['direction', 'status']);
            $table->dropColumn([
                'media_type', 'template_name', 'reply_to_message_id',
                'error_code', 'error_message', 'sent_at', 'failed_at',
            ]);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['channel', 'contact_identifier']);
            $table->dropIndex(['wa_id']);
            $table->dropColumn(['last_inbound_at', 'window_expires_at', 'contact_name', 'wa_id']);
        });
    }
};
