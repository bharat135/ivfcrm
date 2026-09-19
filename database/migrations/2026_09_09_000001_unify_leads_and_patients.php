<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads and patients become one person.
 *
 * Until now a lead only became a patient when somebody pressed "convert", so
 * the clinic ran two lists of the same human beings and marketing spend could
 * never be traced past the enquiry. From here every enquiry — Meta lead ad,
 * click-to-WhatsApp, Google Ads landing page, walk-in, import — owns a patient
 * record from the moment it arrives, and every patient owns an enquiry record
 * so it appears in the funnel. The two tables stay because they hold different
 * facets (acquisition vs. clinical); the pairing is what makes them one record.
 *
 * `journey_stage` is the single field the whole product sorts and filters on:
 * enquiry -> contacted -> consultation -> treatment -> closed / lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Where this person is in the one unified funnel.
            $table->string('journey_stage', 30)->default('enquiry')->after('status')
                ->comment('enquiry, contacted, consultation, treatment, closed, lost');

            // Same normalisation as leads.dedup_hash so a person arriving twice
            // through different channels resolves to one patient.
            $table->string('dedup_hash', 64)->nullable()->after('pincode');

            // When the person first entered the funnel, as opposed to when the
            // clinical record happened to be created.
            $table->timestamp('first_enquiry_at')->nullable()->after('journey_stage');

            $table->index('journey_stage');
            $table->index('dedup_hash');
            $table->index(['center_id', 'journey_stage']);
        });

        Schema::table('leads', function (Blueprint $table) {
            // Google Ads click identifiers. gclid is the classic one; gbraid and
            // wbraid replace it for iOS app/web journeys where Google cannot set
            // a cookie. All three are needed to upload an offline conversion.
            $table->string('gclid', 191)->nullable()->after('utm_content');
            $table->string('gbraid', 191)->nullable()->after('gclid');
            $table->string('wbraid', 191)->nullable()->after('gbraid');

            // Meta click id, for the Conversions API.
            $table->string('fbclid', 255)->nullable()->after('wbraid');

            // Resolved acquisition channel, derived once at intake so reports do
            // not have to re-parse utm strings on every query.
            $table->string('channel', 30)->nullable()->after('fbclid')
                ->comment('meta_ads, google_ads, whatsapp, landing_page, organic, referral, import, manual');

            $table->index('gclid');
            $table->index(['channel', 'created_at']);
        });

        // ------------------------------------------------------------------
        // Backfill. Chunked, because these tables are sized for lakhs of rows.
        // ------------------------------------------------------------------

        $this->backfillPatientsForLeads();
        $this->backfillLeadsForPatients();
        $this->backfillChannels();
    }

    /**
     * Every lead gets a patient record.
     */
    private function backfillPatientsForLeads(): void
    {
        $nextCode = (int) (DB::table('patients')->max('id') ?? 0);

        DB::table('leads')
            ->whereNull('patient_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($leads) use (&$nextCode) {
                foreach ($leads as $lead) {
                    $hash = $lead->dedup_hash
                        ?: ($lead->mobile ? sha1(preg_replace('/\D/', '', $lead->mobile)) : null);

                    // Re-use a patient the clinic already has for this number
                    // rather than creating a second file for the same couple.
                    $existing = $hash
                        ? DB::table('patients')->where('dedup_hash', $hash)->whereNull('deleted_at')->first()
                        : null;

                    if (! $existing && $lead->mobile) {
                        $existing = DB::table('patients')
                            ->where('mobile', $lead->mobile)
                            ->whereNull('deleted_at')
                            ->first();
                    }

                    if ($existing) {
                        DB::table('leads')->where('id', $lead->id)->update(['patient_id' => $existing->id]);

                        if (! $existing->lead_id) {
                            DB::table('patients')->where('id', $existing->id)->update([
                                'lead_id'    => $lead->id,
                                'dedup_hash' => $existing->dedup_hash ?: $hash,
                            ]);
                        }

                        continue;
                    }

                    $nextCode++;

                    $female = $lead->gender !== 'male';

                    $patientId = DB::table('patients')->insertGetId([
                        'patient_code'     => 'PAT-' . str_pad((string) $nextCode, 6, '0', STR_PAD_LEFT),
                        'lead_id'          => $lead->id,
                        'center_id'        => $lead->center_id,
                        'wife_name'        => $female ? $lead->name : null,
                        'husband_name'     => $female ? null : $lead->name,
                        'wife_age'         => $female ? $lead->age : null,
                        'husband_age'      => $female ? null : $lead->age,
                        'mobile'           => $lead->mobile,
                        'alt_mobile'       => $lead->alt_mobile,
                        'email'            => $lead->email,
                        'city_id'          => $lead->city_id,
                        'state_id'         => $lead->state_id,
                        'pincode'          => $lead->pincode,
                        'dedup_hash'       => $hash,
                        'status'           => in_array($lead->status, ['lost', 'junk'], true) ? 'inactive' : 'active',
                        'journey_stage'    => $this->stageForLead($lead),
                        'first_enquiry_at' => $lead->lead_date ?: $lead->created_at,
                        'created_at'       => $lead->created_at,
                        'updated_at'       => now(),
                    ]);

                    DB::table('leads')->where('id', $lead->id)->update(['patient_id' => $patientId]);
                }
            });
    }

    /**
     * Every patient gets an enquiry record, so walk-ins and referrals show up in
     * the same funnel as paid leads instead of being invisible to marketing.
     */
    private function backfillLeadsForPatients(): void
    {
        $walkIn = DB::table('lead_sources')->where('name', 'Walk-in')->value('id')
            ?: DB::table('lead_sources')->whereIn('slug', ['walk-in', 'walkin'])->value('id');

        DB::table('patients')
            ->whereNull('lead_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(500, function ($patients) use ($walkIn) {
                foreach ($patients as $patient) {
                    $hash = $patient->mobile ? sha1(preg_replace('/\D/', '', $patient->mobile)) : null;

                    $name = $patient->wife_name ?: $patient->husband_name;

                    $leadId = DB::table('leads')->insertGetId([
                        'uuid'           => (string) \Illuminate\Support\Str::uuid(),
                        'name'           => $name,
                        'mobile'         => $patient->mobile,
                        'alt_mobile'     => $patient->alt_mobile,
                        'email'          => $patient->email,
                        'age'            => $patient->wife_age ?: $patient->husband_age,
                        'city_id'        => $patient->city_id,
                        'state_id'       => $patient->state_id,
                        'pincode'        => $patient->pincode,
                        'center_id'      => $patient->center_id,
                        'lead_source_id' => $walkIn,
                        'channel'        => 'walk_in',
                        'status'         => 'won',
                        'patient_id'     => $patient->id,
                        'lead_date'      => $patient->created_at,
                        'dedup_hash'     => $hash,
                        'remark'         => 'Created from existing patient record during unification.',
                        'created_at'     => $patient->created_at,
                        'updated_at'     => now(),
                    ]);

                    DB::table('patients')->where('id', $patient->id)->update([
                        'lead_id'          => $leadId,
                        'dedup_hash'       => $patient->dedup_hash ?: $hash,
                        'journey_stage'    => 'treatment',
                        'first_enquiry_at' => $patient->created_at,
                    ]);
                }
            });

        // Patients that already had a lead still need the new columns filled.
        DB::table('patients')
            ->whereNull('dedup_hash')
            ->whereNotNull('mobile')
            ->orderBy('id')
            ->chunkById(1000, function ($patients) {
                foreach ($patients as $patient) {
                    DB::table('patients')->where('id', $patient->id)->update([
                        'dedup_hash'       => sha1(preg_replace('/\D/', '', $patient->mobile)),
                        'first_enquiry_at' => $patient->first_enquiry_at ?: $patient->created_at,
                    ]);
                }
            });
    }

    /**
     * Label historical leads with the channel they actually came from, so the
     * new Google Ads vs Meta Ads reporting is not blank for everything old.
     */
    private function backfillChannels(): void
    {
        DB::table('leads')->whereNull('channel')->update(['channel' => DB::raw(
            "CASE
                WHEN external_lead_id IS NOT NULL THEN 'meta_ads'
                WHEN ad_id IS NOT NULL OR adset_id IS NOT NULL THEN 'meta_ads'
                WHEN LOWER(COALESCE(utm_source,'')) IN ('google','google_ads','googleads','adwords') THEN 'google_ads'
                WHEN LOWER(COALESCE(utm_source,'')) IN ('facebook','fb','instagram','ig','meta') THEN 'meta_ads'
                WHEN LOWER(COALESCE(utm_medium,'')) IN ('cpc','ppc','paid') THEN 'google_ads'
                WHEN utm_source IS NOT NULL THEN 'landing_page'
                ELSE 'manual'
            END"
        )]);
    }

    private function stageForLead(object $lead): string
    {
        return match (true) {
            in_array($lead->status, ['lost', 'junk'], true) => 'lost',
            $lead->status === 'won'                          => 'consultation',
            ! empty($lead->first_contacted_at)               => 'contacted',
            default                                          => 'enquiry',
        };
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['gclid']);
            $table->dropIndex(['channel', 'created_at']);
            $table->dropColumn(['gclid', 'gbraid', 'wbraid', 'fbclid', 'channel']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['journey_stage']);
            $table->dropIndex(['dedup_hash']);
            $table->dropIndex(['center_id', 'journey_stage']);
            $table->dropColumn(['journey_stage', 'dedup_hash', 'first_enquiry_at']);
        });
    }
};
