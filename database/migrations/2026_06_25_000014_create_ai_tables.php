<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-ready structures: call auditing (transcript + sentiment + QA score) and
 * lead scoring (intent/budget/urgency/stage breakdown feeding leads.score).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('analyst_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('ai')->comment('ai, manual');
            $table->longText('transcript')->nullable();
            $table->string('language', 10)->nullable();
            $table->string('sentiment', 20)->nullable()->comment('positive, neutral, negative');
            $table->decimal('sentiment_score', 5, 2)->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable()->comment('0-100 QA score');
            $table->json('scorecard')->nullable()->comment('Parameter-wise QA breakdown');
            $table->json('flags')->nullable()->comment('Compliance / red-flag markers');
            $table->text('summary')->nullable();
            $table->string('status', 20)->default('pending')->comment('pending, processing, completed, failed');
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('lead_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('intent')->nullable();
            $table->unsignedTinyInteger('budget')->nullable();
            $table->unsignedTinyInteger('urgency')->nullable();
            $table->unsignedTinyInteger('stage_fit')->nullable();
            $table->unsignedTinyInteger('total_score')->nullable();
            $table->string('band', 20)->nullable()->comment('hot, warm, cold');
            $table->string('model_version', 40)->nullable();
            $table->json('factors')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_scores');
        Schema::dropIfExists('call_audits');
    }
};
