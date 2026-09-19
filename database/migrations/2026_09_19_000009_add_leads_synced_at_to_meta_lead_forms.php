<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `last_synced_at` was shared between the hourly form-metadata sync and the
 * lead pull, so the lead window always started "an hour ago" and older
 * submissions were never imported. Lead pulls get their own watermark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_lead_forms', function (Blueprint $table) {
            $table->timestamp('leads_synced_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('meta_lead_forms', function (Blueprint $table) {
            $table->dropColumn('leads_synced_at');
        });
    }
};
