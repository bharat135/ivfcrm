<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An enquiry stays a lead until the couple actually walks in. The patient row
 * behind every lead is kept (the profile screen hangs off it), but it carries
 * no patient ID and stays off the Patients list until registered_at is set by
 * a visit.
 *
 * Existing rows: anyone with a visited appointment, or registered at the front
 * desk as a walk-in, keeps their ID; everyone else goes back to lead-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('patient_code', 40)->nullable()->change();
            $table->timestamp('registered_at')->nullable()->after('journey_stage')->index();
        });

        // First visit, from either side of the appointment link.
        DB::statement("
            UPDATE patients p
            JOIN (
                SELECT p2.id AS patient_id, MIN(a.updated_at) AS visited_at
                FROM patients p2
                JOIN appointments a
                  ON a.status = 'visited' AND a.deleted_at IS NULL
                 AND (a.patient_id = p2.id OR (p2.lead_id IS NOT NULL AND a.lead_id = p2.lead_id))
                GROUP BY p2.id
            ) v ON v.patient_id = p.id
            SET p.registered_at = v.visited_at
        ");

        // Walk-ins were registered by somebody standing at the desk.
        DB::statement("
            UPDATE patients p
            JOIN leads l ON l.id = p.lead_id
            SET p.registered_at = p.created_at
            WHERE p.registered_at IS NULL AND l.channel = 'walk_in'
        ");

        DB::table('patients')->whereNull('registered_at')->update(['patient_code' => null]);
    }

    public function down(): void
    {
        DB::table('patients')->whereNull('patient_code')->update([
            'patient_code' => DB::raw("CONCAT('PAT-', LPAD(id, 6, '0'))"),
        ]);

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('registered_at');
            $table->string('patient_code', 40)->nullable(false)->change();
        });
    }
};
