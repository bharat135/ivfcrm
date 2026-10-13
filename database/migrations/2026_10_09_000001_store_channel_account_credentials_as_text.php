<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ChannelAccount casts `credentials` as encrypted:array, which stores a
 * ciphertext string, not JSON. MySQL 8 validates JSON columns on write and
 * rejected every insert ("Invalid JSON text"), so no channel account could
 * ever be saved. A text column holds the ciphertext as intended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_accounts', function (Blueprint $table) {
            $table->text('credentials')->nullable()->comment('Encrypted provider creds')->change();
        });
    }

    public function down(): void
    {
        // Rows written after this migration hold ciphertext, which a JSON
        // column would refuse; leave the column as text.
    }
};
