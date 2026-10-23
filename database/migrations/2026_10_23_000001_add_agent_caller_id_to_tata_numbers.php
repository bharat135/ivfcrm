<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DID can be the caller id of one agent, ahead of the centre's DID and the
 * default: "when Vishal calls, show this number".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tata_numbers', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('center_id')->constrained('users')->nullOnDelete()
                ->comment('Use this DID as caller id when this agent calls');
        });
    }

    public function down(): void
    {
        Schema::table('tata_numbers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
