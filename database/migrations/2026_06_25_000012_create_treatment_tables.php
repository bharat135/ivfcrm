<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counselling sessions and the IVF treatment timeline.
 * A patient can run multiple treatment_cycles; each cycle has ordered
 * cycle_events (consultation, investigation, stimulation, egg retrieval,
 * fertilization, embryo transfer, pregnancy tracking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counselling_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('counsellor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status', 20)->default('scheduled')->comment('scheduled, completed, no_show, cancelled');
            $table->string('outcome', 40)->nullable()->comment('treatment_agreed, thinking, declined');
            $table->decimal('quoted_amount', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['counsellor_id', 'scheduled_at']);
        });

        Schema::create('treatment_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cycle_code', 40)->nullable();
            $table->string('type', 40)->default('ivf')->comment('ivf, iui, icsi, fet');
            $table->unsignedSmallInteger('cycle_number')->default(1);
            $table->string('current_stage', 40)->nullable();
            $table->string('status', 20)->default('active')->comment('active, completed, cancelled, abandoned');
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->decimal('package_amount', 12, 2)->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('outcome', 40)->nullable()->comment('pregnancy_positive, pregnancy_negative, delivery');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'status']);
            $table->index(['center_id', 'status']);
        });

        Schema::create('cycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treatment_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage', 40)->comment('consultation, investigation, stimulation, egg_retrieval, fertilization, embryo_transfer, pregnancy_tracking');
            $table->string('title')->nullable();
            $table->dateTime('event_date')->nullable();
            $table->text('notes')->nullable();
            $table->json('data')->nullable()->comment('Stage-specific clinical fields');
            $table->timestamps();
            $table->index(['treatment_cycle_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_events');
        Schema::dropIfExists('treatment_cycles');
        Schema::dropIfExists('counselling_sessions');
    }
};
