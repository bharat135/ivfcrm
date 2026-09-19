<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram Direct and Messenger threads in the same inbox as WhatsApp.
 *
 * Both channels identify a person by an opaque, page-scoped id (a PSID on
 * Messenger, an IGSID on Instagram) rather than a phone number, so a thread
 * carries the profile fields Meta gives us instead of a contact's own details.
 *
 * `page_id` matters because the same CRM can serve several Pages/IG accounts,
 * and a PSID is only unique *within* a Page — two Pages can legitimately hand
 * out the same id for different people.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('contact_username')->nullable()->after('contact_name')
                ->comment('Instagram handle, when the platform exposes one');
            $table->string('avatar_url', 1024)->nullable()->after('contact_username');
            $table->string('page_id', 60)->nullable()->after('wa_id')
                ->comment('Page / IG account that owns this thread');

            // A PSID is unique per Page, not globally.
            $table->index(['page_id', 'contact_identifier'], 'conversations_page_contact_index');
        });

        Schema::table('message_attachments', function (Blueprint $table) {
            // Messenger and Instagram hand over a short-lived CDN URL rather
            // than a media id that can be resolved later, so the URL itself has
            // to be recorded for the download job to use.
            $table->text('source_url')->nullable()->after('provider_media_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Meta echoes back messages the business sent from any tool. They
            // must be stored as outbound, never counted as a customer reply.
            $table->boolean('is_echo')->default(false)->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('is_echo');
        });

        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_page_contact_index');
            $table->dropColumn(['contact_username', 'avatar_url', 'page_id']);
        });
    }
};
