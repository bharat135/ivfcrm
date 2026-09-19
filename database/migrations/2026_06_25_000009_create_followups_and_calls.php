<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up scheduling and the telephony call log (click-to-call + disposition).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('disposition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->default('call')->comment('call, whatsapp, email, visit');
            $table->timestamp('scheduled_at')->index();
            $table->string('status', 20)->default('pending')->comment('pending, done, missed, cancelled');
            $table->timestamp('completed_at')->nullable();
            $table->text('remark')->nullable();
            $table->boolean('reminder_sent')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'status', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30)->nullable()->comment('exotel, knowlarity, myoperator');
            $table->string('direction', 12)->default('outbound')->comment('inbound, outbound');
            $table->string('from_number', 20)->nullable();
            $table->string('to_number', 20)->nullable();
            $table->string('provider_call_id')->nullable()->index();
            $table->string('status', 30)->nullable()->comment('completed, no-answer, busy, failed, switched-off');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('recording_url')->nullable();
            $table->foreignId('disposition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sub_disposition_id')->nullable()->constrained('dispositions')->nullOnDelete();
            $table->text('remark')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('follow_ups');
    }
};
