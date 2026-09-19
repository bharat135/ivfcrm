<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Flows — multi-screen forms that run inside WhatsApp.
 *
 * Three tables, one per stage of the lifecycle:
 *
 *   whatsapp_flows           the Flow definition, mirrored from Meta
 *   whatsapp_flow_sessions   one row per send; the flow_token issued to a
 *                            contact is what correlates every later callback
 *                            back to a conversation and a lead
 *   whatsapp_flow_responses  the completed submission and what the CRM did
 *                            with it
 *
 * A Flow can run in two modes. A *static* flow renders entirely from its own
 * JSON. An *endpoint* flow calls back into the CRM between screens (encrypted,
 * see FlowEncryption) so options like appointment slots can be live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id', 60)->nullable()->unique()->comment('Meta Flow ID');
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();

            // Meta lifecycle: DRAFT -> PUBLISHED -> DEPRECATED. Also BLOCKED /
            // THROTTLED when Meta suspends a flow for quality reasons.
            $table->string('status', 20)->default('DRAFT');
            $table->json('categories')->nullable()->comment('LEAD_GENERATION, APPOINTMENT_BOOKING, SURVEY…');

            $table->string('json_version', 10)->default('7.0');
            $table->string('data_api_version', 10)->nullable()->comment('Set only for endpoint flows');
            $table->text('endpoint_uri')->nullable();
            $table->longText('flow_json')->nullable();

            // Which starter the flow was built from, and how the CRM should
            // interpret what comes back.
            $table->string('purpose', 40)->default('custom')
                ->comment('lead_qualification, appointment_booking, feedback, custom');
            $table->json('field_map')->nullable()->comment('Flow field name -> CRM lead attribute');

            // Routing applied to anything created from a submission.
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('auto_create_lead')->default(true);
            $table->boolean('auto_book_appointment')->default(false);

            $table->text('preview_url')->nullable();
            $table->timestamp('preview_expires_at')->nullable();
            $table->json('validation_errors')->nullable();
            $table->text('sync_error')->nullable();

            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('response_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'purpose']);
        });

        /**
         * The flow_token is the only thing tying an in-flight Flow back to the
         * CRM: Meta echoes it on every endpoint call and on the final reply,
         * and those arrive as separate requests with no other context.
         */
        Schema::create('whatsapp_flow_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('flow_token', 100)->unique();
            $table->string('wa_id', 30)->index();
            $table->string('status', 20)->default('sent')
                ->comment('sent, opened, in_progress, completed, expired, failed');
            $table->string('current_screen', 60)->nullable();
            $table->json('state')->nullable()->comment('Answers accumulated across screens');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_flow_id', 'status']);
        });

        Schema::create('whatsapp_flow_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_flow_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('flow_token', 100)->nullable()->index();
            $table->string('wa_id', 30)->nullable()->index();
            $table->string('screen', 60)->nullable();
            $table->json('answers')->comment('Decoded response_json from the flow');

            $table->string('status', 20)->default('received')
                ->comment('received, processed, failed, ignored');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_flow_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flow_responses');
        Schema::dropIfExists('whatsapp_flow_sessions');
        Schema::dropIfExists('whatsapp_flows');
    }
};
