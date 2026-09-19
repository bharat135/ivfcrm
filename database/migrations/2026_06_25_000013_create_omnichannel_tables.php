<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Omnichannel unified inbox.
 *  - channel_accounts: configured channel endpoints per center (Wati number,
 *    SMS sender id, mailbox, telephony DID, FB/IG page...).
 *  - conversations: one thread per (contact, channel).
 *  - messages: inbound/outbound items with provider ids & delivery status.
 *  - message_attachments: media for a message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 30)->index()->comment('whatsapp, sms, email, voice, webchat, messenger, instagram');
            $table->string('provider', 40)->comment('wati, msg91, textlocal, smtp, office365, exotel, knowlarity, myoperator, meta');
            $table->string('name');
            $table->string('identifier')->nullable()->comment('Phone/sender id/email/page id');
            $table->json('credentials')->nullable()->comment('Encrypted provider creds');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['channel', 'is_active']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 30)->index();
            $table->foreignId('channel_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_identifier')->nullable()->comment('Phone/email/handle of the contact');
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('open')->comment('open, pending, snoozed, closed');
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('is_escalated')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['assigned_to', 'status']);
            $table->index(['lead_id', 'channel']);
            $table->index(['status', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('Agent for outbound');
            $table->string('direction', 10)->comment('inbound, outbound');
            $table->string('type', 20)->default('text')->comment('text, template, media, note');
            $table->longText('body')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('status', 20)->default('queued')->comment('queued, sent, delivered, read, failed');
            $table->boolean('is_internal_note')->default(false);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 100)->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('channel_accounts');
    }
};
