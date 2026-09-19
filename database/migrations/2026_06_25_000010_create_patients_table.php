<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patients — a lead becomes a patient on conversion. Designed for 10L+ rows.
 * Couple-centric (husband/wife) per IVF domain. Backfills the leads.patient_id FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('patient_code', 40)->unique();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('primary_doctor_id')->nullable()->constrained('doctors')->nullOnDelete();

            // Couple demographics
            $table->string('wife_name')->nullable();
            $table->string('husband_name')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('alt_mobile', 20)->nullable();
            $table->string('email')->nullable();
            $table->date('wife_dob')->nullable();
            $table->date('husband_dob')->nullable();
            $table->unsignedTinyInteger('wife_age')->nullable();
            $table->unsignedTinyInteger('husband_age')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pincode', 10)->nullable();

            // Clinical summary
            $table->unsignedSmallInteger('infertility_duration_months')->nullable();
            $table->boolean('previous_ivf')->default(false);
            $table->string('diagnosis')->nullable();
            $table->text('treatment_notes')->nullable();

            $table->string('status', 30)->default('active');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('mobile');
            $table->index(['center_id', 'status']);
        });

        Schema::create('patient_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40)->comment('report, prescription, consent, scan, other');
            $table->string('title')->nullable();
            $table->string('path');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'type']);
        });

        // Backfill the FK declared in the leads migration.
        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();
            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
            $table->dropIndex(['patient_id']);
        });
        Schema::dropIfExists('patient_documents');
        Schema::dropIfExists('patients');
    }
};
