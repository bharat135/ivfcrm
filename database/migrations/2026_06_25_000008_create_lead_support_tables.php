<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supporting tables around leads: activity timeline, notes, tags (polymorphic),
 * assignment history, the assignment-rule builder, and bulk-import bookkeeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Unified activity timeline (status change, call, note, assignment, message...).
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index()->comment('note, call, status, assignment, message, followup, system');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->string('color', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable');
            $table->timestamps();
            $table->unique(['tag_id', 'taggable_id', 'taggable_type'], 'taggables_unique');
        });

        // Assignment history (who got the lead, by which method, when).
        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 30)->comment('round_robin, skill, center, pincode, state, language, manual');
            $table->timestamp('assigned_at');
            $table->timestamp('reassigned_at')->nullable();
            $table->timestamps();
            $table->index(['lead_id', 'assigned_at']);
        });

        // Rule builder for the distribution engine. conditions/targets are JSON.
        Schema::create('assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('method', 30)->comment('round_robin, skill, center, pincode, state, language');
            $table->unsignedSmallInteger('priority')->default(0)->comment('Lower = evaluated first');
            $table->json('conditions')->nullable()->comment('Match criteria: source, campaign, state, pincode...');
            $table->json('targets')->nullable()->comment('User/team/center pool to assign into');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'priority']);
        });

        // Bulk lead upload runs.
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('status', 20)->default('pending')->comment('pending, processing, completed, failed');
            $table->json('column_mapping')->nullable();
            $table->json('options')->nullable()->comment('dedup strategy, default source, etc.');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lead_import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('row_data')->nullable();
            $table->text('error');
            $table->timestamps();
            $table->index('lead_import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_errors');
        Schema::dropIfExists('lead_imports');
        Schema::dropIfExists('assignment_rules');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('lead_activities');
    }
};
