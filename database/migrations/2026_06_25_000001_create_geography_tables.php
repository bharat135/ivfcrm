<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geography master tables: states -> cities -> pincodes.
 * Used for lead origin, pincode-based lead distribution and analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10)->nullable();
            $table->string('country', 5)->default('IN');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['name', 'country']);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['state_id', 'name']);
        });

        Schema::create('pincodes', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 10);
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('state_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('pincode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pincodes');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
    }
};
