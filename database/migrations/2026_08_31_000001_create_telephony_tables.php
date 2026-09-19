<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telephony: Asterisk/MicroSIP softphone calling and WhatsApp Business Calling.
 *
 * One `call_logs` row is the single record of a conversation regardless of how
 * it was carried — PSTN through Asterisk, an internal extension-to-extension
 * call, or a WhatsApp voice call. `channel` distinguishes them, and the timing
 * columns (ring/talk/hold/wrap) are shared so telecaller productivity reports
 * work across all three.
 *
 * Credentials are never stored here in plain text: SIP secrets are encrypted on
 * the model, and Asterisk AMI/ARI credentials live in `settings` (encrypted).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // SIP extensions — the agent's softphone identity on the PBX.
        // A dedicated table (rather than columns on users) so an extension can
        // exist unassigned, be reassigned between agents, and keep its history.
        // ---------------------------------------------------------------
        Schema::create('sip_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('extension', 20)->unique()->comment('Dialable number, e.g. 1001');
            $table->string('username', 80)->nullable()->comment('SIP auth user (defaults to extension)');
            $table->text('password')->nullable()->comment('Encrypted SIP secret');
            $table->string('domain')->nullable()->comment('PBX host / SIP domain');
            $table->unsignedSmallInteger('port')->default(5060);
            $table->string('transport', 10)->default('udp')->comment('udp, tcp, tls, ws, wss');
            $table->string('context', 60)->default('from-internal');
            $table->string('caller_id')->nullable()->comment('Outbound CLI presented to the customer');
            $table->string('technology', 20)->default('PJSIP')->comment('PJSIP, SIP, IAX2');
            $table->boolean('webrtc')->default(false)->comment('Browser softphone rather than MicroSIP');
            $table->boolean('record_calls')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('registration_status', 20)->nullable()->comment('registered, unavailable, unknown');
            $table->timestamp('last_registered_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });

        // ---------------------------------------------------------------
        // Agent call presence, used by the dialer and the supervisor wallboard.
        // ---------------------------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            $table->string('call_status', 20)->default('offline')->after('is_available')
                ->comment('offline, idle, ringing, on_call, wrapup, break, dnd');
            $table->timestamp('call_status_changed_at')->nullable()->after('call_status');
            $table->unsignedBigInteger('active_call_log_id')->nullable()->after('call_status_changed_at');
            $table->index(['call_status', 'is_active']);
        });

        // ---------------------------------------------------------------
        // Extend the existing call log for PBX + WhatsApp calling.
        // ---------------------------------------------------------------
        Schema::table('call_logs', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
            $table->string('channel', 20)->default('pstn')->after('provider')
                ->comment('pstn, internal, whatsapp, webrtc');

            // Asterisk correlation. `linkedid` groups the legs of one call.
            $table->string('channel_id')->nullable()->after('provider_call_id')->comment('Asterisk Uniqueid');
            $table->string('linked_id')->nullable()->after('channel_id')->comment('Asterisk Linkedid');
            $table->string('extension', 20)->nullable()->after('linked_id');
            $table->string('queue', 60)->nullable()->after('extension');
            $table->string('hangup_cause', 60)->nullable()->after('status');
            $table->string('hangup_by', 20)->nullable()->comment('agent, customer, system')->after('hangup_cause');

            // Timing breakdown for productivity reporting.
            $table->unsignedInteger('ring_seconds')->default(0)->after('duration_seconds');
            $table->unsignedInteger('talk_seconds')->default(0)->after('ring_seconds');
            $table->unsignedInteger('hold_seconds')->default(0)->after('talk_seconds');
            $table->unsignedInteger('wrap_seconds')->default(0)->after('hold_seconds');
            $table->timestamp('answered_at')->nullable()->after('started_at');

            // Recording: a path on a disk is preferred over a bare URL so
            // recordings can be served through an authorised route.
            $table->string('recording_path')->nullable()->after('recording_url');
            $table->string('recording_disk', 30)->nullable()->after('recording_path');
            $table->boolean('is_recorded')->default(false)->after('recording_disk');

            // Transfers keep the original leg discoverable.
            $table->unsignedBigInteger('parent_call_log_id')->nullable()->after('is_recorded');
            $table->foreignId('transferred_to')->nullable()->after('parent_call_log_id')
                ->constrained('users')->nullOnDelete();

            // WhatsApp calling.
            $table->string('wa_call_id')->nullable()->after('transferred_to')->index();
            $table->string('wa_direction', 30)->nullable()->after('wa_call_id')
                ->comment('BUSINESS_INITIATED, USER_INITIATED');

            $table->json('meta')->nullable()->after('remark');

            $table->index('linked_id');
            $table->index(['channel', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->foreign('parent_call_log_id')->references('id')->on('call_logs')->nullOnDelete();
        });

        // ---------------------------------------------------------------
        // Raw event stream (AMI/dialplan/Meta) — the audit trail behind a call.
        // ---------------------------------------------------------------
        Schema::create('call_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_log_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('source', 20)->comment('asterisk, whatsapp, crm');
            $table->string('event', 60)->index();
            $table->string('correlation_id')->nullable()->index()->comment('Uniqueid / wa call id');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            $table->index(['call_log_id', 'occurred_at']);
        });

        // ---------------------------------------------------------------
        // WhatsApp call permissions.
        // Meta requires an explicit, time-boxed permission before a business
        // may place a voice call to a user.
        // ---------------------------------------------------------------
        Schema::create('whatsapp_call_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('wa_id', 30)->index();
            $table->string('status', 20)->default('pending')
                ->comment('pending, approved, rejected, expired, revoked');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('calls_remaining')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['wa_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_call_permissions');
        Schema::dropIfExists('call_events');

        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropForeign(['parent_call_log_id']);
            $table->dropForeign(['transferred_to']);
            $table->dropForeign(['patient_id']);
            $table->dropIndex(['linked_id']);
            $table->dropIndex(['channel', 'created_at']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['wa_call_id']);
            $table->dropColumn([
                'patient_id', 'channel', 'channel_id', 'linked_id', 'extension', 'queue',
                'hangup_cause', 'hangup_by', 'ring_seconds', 'talk_seconds', 'hold_seconds',
                'wrap_seconds', 'answered_at', 'recording_path', 'recording_disk', 'is_recorded',
                'parent_call_log_id', 'transferred_to', 'wa_call_id', 'wa_direction', 'meta',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['call_status', 'is_active']);
            $table->dropColumn(['call_status', 'call_status_changed_at', 'active_call_log_id']);
        });

        Schema::dropIfExists('sip_extensions');
    }
};
