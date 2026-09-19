<?php

namespace App\Services;

use App\Jobs\ReportConversionJob;
use App\Models\Lead;
use App\Models\Patient;
use App\Support\PatientJourney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the lead and patient facets of one person in step.
 *
 * A lead and a patient are not two records in this CRM, they are two views of
 * the same human being: the acquisition facet (where they came from, who is
 * chasing them) and the clinical facet (the couple, the diagnosis, the cycles).
 * This service is the only place allowed to create the pairing, so there is
 * exactly one rule for how an enquiry becomes a patient file — immediately,
 * automatically, on every intake path.
 */
class PatientLinker
{
    /**
     * Return the patient for a lead, creating or adopting one if needed.
     *
     * Idempotent: safe to call on every save, every webhook redelivery and
     * every import row.
     */
    public function ensureFor(Lead $lead): ?Patient
    {
        if ($lead->patient_id && ($patient = Patient::find($lead->patient_id))) {
            $this->syncDemographics($lead, $patient);

            return $patient;
        }

        try {
            return DB::transaction(function () use ($lead) {
                $hash = $this->hash($lead->mobile) ?: $lead->dedup_hash;

                // Adopt the couple's existing file rather than opening a second
                // one — the same number enquiring again is the same patient.
                $patient = $this->findExisting($hash, $lead->mobile, $lead->email);

                if ($patient) {
                    $this->adopt($lead, $patient, $hash);

                    return $patient;
                }

                $patient = Patient::create($this->attributesFromLead($lead, $hash));

                $lead->forceFill(['patient_id' => $patient->id])->saveQuietly();

                return $patient;
            });
        } catch (\Throwable $e) {
            // A missing patient file must never lose the enquiry itself, so
            // this degrades rather than throwing back into the intake path.
            Log::error('Could not create the patient record for a lead', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Move a person forward in the funnel. Never moves them backwards, so a
     * late-arriving webhook cannot demote someone already in treatment.
     */
    public function advance(Lead|Patient $subject, string $stage, ?string $reason = null): void
    {
        $patient = $subject instanceof Lead ? $this->ensureFor($subject) : $subject;

        if (! $patient || ! PatientJourney::isValid($stage)) {
            return;
        }

        if (! PatientJourney::advances($patient->journey_stage, $stage)) {
            return;
        }

        $patient->forceFill([
            'journey_stage' => $stage,
            'status'        => $stage === PatientJourney::LOST ? 'inactive' : 'active',
        ])->save();

        Log::debug('Patient journey advanced', [
            'patient_id' => $patient->id,
            'stage'      => $stage,
            'reason'     => $reason,
        ]);

        // Tell whichever ad network paid for this person that they progressed.
        // Queued, because reporting must never slow down or break the clinical
        // action that triggered it.
        if ($stage !== PatientJourney::LOST) {
            ReportConversionJob::dispatch($patient->id, $stage);
        }
    }

    /**
     * Recompute the stage from the lead's own state. Called after disposition
     * changes so the unified list reflects what the telecaller just did.
     */
    public function syncStageFromLead(Lead $lead): void
    {
        $this->advance($lead, $this->stageForLead($lead), 'lead status: ' . $lead->status);
    }

    public function stageForLead(Lead $lead): string
    {
        return match (true) {
            in_array($lead->status, ['lost', 'junk'], true) => PatientJourney::LOST,
            $lead->status === 'won'                          => PatientJourney::CONSULTATION,
            $lead->contact_attempts > 0
                || $lead->first_contacted_at !== null        => PatientJourney::CONTACTED,
            default                                          => PatientJourney::ENQUIRY,
        };
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Fill blanks on the patient from the lead. Existing clinical values always
     * win — a marketing form must not overwrite what a doctor typed.
     */
    private function syncDemographics(Lead $lead, Patient $patient): void
    {
        $female = $lead->gender !== 'male';
        $nameField = $female ? 'wife_name' : 'husband_name';
        $ageField  = $female ? 'wife_age' : 'husband_age';

        $fill = [
            $nameField   => $lead->name,
            $ageField    => $lead->age,
            'mobile'     => $lead->mobile,
            'alt_mobile' => $lead->alt_mobile,
            'email'      => $lead->email,
            'city_id'    => $lead->city_id,
            'state_id'   => $lead->state_id,
            'pincode'    => $lead->pincode,
            'center_id'  => $lead->center_id,
            'lead_id'    => $lead->id,
            'dedup_hash' => $this->hash($lead->mobile),
        ];

        $dirty = [];

        foreach ($fill as $column => $value) {
            if (! blank($value) && blank($patient->{$column})) {
                $dirty[$column] = $value;
            }
        }

        if ($dirty !== []) {
            $patient->forceFill($dirty)->save();
        }
    }

    private function adopt(Lead $lead, Patient $patient, ?string $hash): void
    {
        $lead->forceFill(['patient_id' => $patient->id])->saveQuietly();

        $patient->forceFill(array_filter([
            'lead_id'          => $patient->lead_id ?: $lead->id,
            'dedup_hash'       => $patient->dedup_hash ?: $hash,
            'first_enquiry_at' => $patient->first_enquiry_at ?: ($lead->lead_date ?: $lead->created_at),
        ]))->save();

        $this->syncDemographics($lead, $patient);
    }

    private function findExisting(?string $hash, ?string $mobile, ?string $email): ?Patient
    {
        if ($hash) {
            $patient = Patient::where('dedup_hash', $hash)->first();

            if ($patient) {
                return $patient;
            }
        }

        if ($mobile) {
            $patient = Patient::where('mobile', $mobile)->first();

            if ($patient) {
                return $patient;
            }
        }

        // Email is a weaker key than a phone number — only trust it when there
        // is no number at all to go on.
        if (! $mobile && $email) {
            return Patient::where('email', $email)->first();
        }

        return null;
    }

    /**
     * The couple's dates live on both facets of the same person; whichever
     * screen edited them, the other must show the same numbers.
     */
    public function syncCoupleDates(Lead|Patient $from): void
    {
        $to = $from instanceof Lead ? $from->patient : $from->lead;

        if (! $to) {
            return;
        }

        $dates = [
            'wife_dob'      => $from->wife_dob,
            'husband_dob'   => $from->husband_dob,
            'marriage_date' => $from->marriage_date,
            'trying_since'  => $from->trying_since,
        ];

        if ($to instanceof Patient) {
            $dates['wife_age']    = Patient::yearsSince($from->wife_dob);
            $dates['husband_age'] = Patient::yearsSince($from->husband_dob);
        } elseif ($from instanceof Patient) {
            $dates['age'] = Patient::yearsSince($from->wife_dob) ?? Patient::yearsSince($from->husband_dob) ?? $to->age;
        }

        $to->forceFill($dates)->saveQuietly();
    }

    private function attributesFromLead(Lead $lead, ?string $hash): array
    {
        $female = $lead->gender !== 'male';

        return [
            'lead_id'          => $lead->id,
            'center_id'        => $lead->center_id,
            'wife_name'        => $female ? $lead->name : null,
            'husband_name'     => $female ? null : $lead->name,
            'wife_age'         => $lead->wife_age_years ?? ($female ? $lead->age : null),
            'husband_age'      => $lead->husband_age_years ?? ($female ? null : $lead->age),
            'wife_dob'         => $lead->wife_dob,
            'husband_dob'      => $lead->husband_dob,
            'marriage_date'    => $lead->marriage_date,
            'trying_since'     => $lead->trying_since,
            'mobile'           => $lead->mobile,
            'alt_mobile'       => $lead->alt_mobile,
            'email'            => $lead->email,
            'city_id'          => $lead->city_id,
            'state_id'         => $lead->state_id,
            'pincode'          => $lead->pincode,
            'dedup_hash'       => $hash,
            'status'           => 'active',
            'journey_stage'    => $this->stageForLead($lead),
            'first_enquiry_at' => $lead->lead_date ?: now(),
        ];
    }

    private function hash(?string $mobile): ?string
    {
        if (! $mobile) {
            return null;
        }

        $digits = \App\Support\PhoneNumber::indian($mobile);

        return $digits === '' ? null : sha1($digits);
    }
}
