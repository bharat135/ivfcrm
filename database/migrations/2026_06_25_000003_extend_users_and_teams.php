<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the framework users table with CRM/operational attributes and
 * adds teams for skill/center based lead distribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code', 40)->nullable()->unique()->after('id');
            $table->string('phone', 20)->nullable()->after('email');
            $table->foreignId('center_id')->nullable()->after('phone')->constrained()->nullOnDelete();
            $table->foreignId('reports_to')->nullable()->after('center_id')->constrained('users')->nullOnDelete();
            $table->string('designation')->nullable()->after('reports_to');
            $table->string('language', 10)->default('en')->after('designation');
            $table->boolean('is_active')->default(true)->after('language');
            // Single-device login: stamp of the currently valid session/token.
            $table->string('current_session_id')->nullable()->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('current_session_id');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            // Telecaller capacity / availability for the distribution engine.
            $table->unsignedInteger('daily_lead_cap')->nullable()->after('last_login_ip');
            $table->boolean('is_available')->default(true)->after('daily_lead_cap');
            $table->json('meta')->nullable()->after('is_available');
            $table->softDeletes();

            $table->index(['center_id', 'is_active']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->comment('Team lead user')->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('center_id');
            $table->dropConstrainedForeignId('reports_to');
            $table->dropColumn([
                'employee_code', 'phone', 'designation', 'language', 'is_active',
                'current_session_id', 'last_login_at', 'last_login_ip',
                'daily_lead_cap', 'is_available', 'meta', 'deleted_at',
            ]);
        });
    }
};
