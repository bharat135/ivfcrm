<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-first calling on Smartflo goes through the Click-to-Call Support
 * API, whose destination (which agent rings once the customer answers) is
 * fixed per API key in the Smartflo portal. So each agent carries their own
 * key; the account-wide key in settings is the fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('tata_c2c_support_key')->nullable()->after('tata_agent_number')
                ->comment('Encrypted Smartflo Click-to-Call Support API key whose destination is this agent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tata_c2c_support_key');
        });
    }
};
