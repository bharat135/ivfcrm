<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctors. Optionally linked to a login user (a doctor who also uses the CRM).
 * Used for appointment calendars, doctor-wise analytics and treatment ownership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('center_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('code', 40)->nullable()->unique();
            $table->string('qualification')->nullable();
            $table->string('specialization')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->unsignedSmallInteger('consultation_minutes')->default(30);
            $table->decimal('consultation_fee', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['center_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
