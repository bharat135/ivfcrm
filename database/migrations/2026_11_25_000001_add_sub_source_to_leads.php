<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-text sub-source under the lead source, e.g. source "Camp Lead" with
 * sub-source "Panvel camp, November". Set from a bulk upload, either for the
 * whole file or per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('sub_source', 150)->nullable()->after('lead_source_id');
            $table->index('sub_source');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['sub_source']);
            $table->dropColumn('sub_source');
        });
    }
};
