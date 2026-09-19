<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable disposition tree (primary -> secondary via parent_id) and
 * the patient-journey pipeline stages. Both are admin-editable masters so the
 * call-center disposition engine and the kanban pipeline are data-driven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('dispositions')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug', 100)->index();
            // Behaviour flags driving the disposition engine.
            $table->boolean('requires_remark')->default(true);
            $table->boolean('requires_followup')->default(false);
            $table->boolean('is_won')->default(false)->comment('Marks conversion');
            $table->boolean('is_lost')->default(false)->comment('Closes the lead');
            // Optional stage this disposition moves the lead to.
            $table->foreignId('moves_to_stage_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('color', 20)->nullable();
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Now that pipeline_stages exists, wire the disposition FK.
        Schema::table('dispositions', function (Blueprint $table) {
            $table->foreign('moves_to_stage_id')->references('id')->on('pipeline_stages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dispositions', function (Blueprint $table) {
            $table->dropForeign(['moves_to_stage_id']);
        });
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('dispositions');
    }
};
