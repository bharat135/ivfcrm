<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tata Tele Business Services (Smartflo) click-to-call rings the agent on a
 * number Smartflo knows — their registered mobile, Smartflo agent id or
 * extension. It is a different identity from the Asterisk SIP extension, so it
 * gets its own column rather than overloading sip_extensions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('tata_agent_number', 30)->nullable()->after('active_call_log_id')
                ->comment('Smartflo agent mobile / agent id / extension used for click-to-call');
            $table->index('tata_agent_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tata_agent_number']);
            $table->dropColumn('tata_agent_number');
        });
    }
};
