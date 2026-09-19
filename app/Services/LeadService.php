<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSource;
use App\Models\Patient;
use App\Repositories\LeadRepository;
use App\Support\AcquisitionChannel;
use App\Support\PatientJourney;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(
        private LeadRepository $repo,
        private AssignmentEngine $assignmentEngine,
        private PatientLinker $patients,
    ) {}

    public function create(array $data, bool $autoAssign = true): Lead
    {
        return DB::transaction(function () use ($data, $autoAssign) {
            $duplicates = $this->repo->findDuplicates($data);

            $data['channel'] = $data['channel'] ?? $this->resolveChannel($data);

            if ($duplicates->isNotEmpty()) {
                $strategy = config('crm.dedup_strategy', 'ignore');
                if ($strategy === 'ignore') {
                    // Even an ignored duplicate must own a patient file — the
                    // record may predate unification or have lost its link.
                    $existing = $duplicates->first();
                    $this->patients->ensureFor($existing);

                    return $existing;
                }
                if ($strategy === 'merge') {
                    return $this->mergeLead($duplicates->first(), $data);
                }
            }

            $lead = $this->repo->create(array_merge($data, [
                'lead_date' => $data['lead_date'] ?? now(),
            ]));

            if ($autoAssign && empty($lead->assigned_to)) {
                $this->assignmentEngine->assign($lead);
            } elseif ($lead->assigned_to) {
                $this->assignmentEngine->recordManual($lead, Auth::id());
            }

            // Leads and patients are the same person, so the patient file is
            // opened here rather than waiting for somebody to press "convert".
            $this->patients->ensureFor($lead);

            $this->logActivity($lead, 'system', 'Lead Created', 'Lead was created via ' . ($data['source_name'] ?? 'system'));

            return $lead->refresh();
        });
    }

    public function updateDisposition(Lead $lead, int $dispositionId, ?int $subDispositionId, string $remark, ?string $nextFollowUpAt = null): Lead
    {
        return DB::transaction(function () use ($lead, $dispositionId, $subDispositionId, $remark, $nextFollowUpAt) {
            $disposition = \App\Models\Disposition::findOrFail($dispositionId);

            $updates = [
                'disposition_id'     => $dispositionId,
                'sub_disposition_id' => $subDispositionId,
                'remark'             => $remark,
                'last_activity_at'   => now(),
            ];

            if ($disposition->moves_to_stage_id) {
                $updates['stage_id'] = $disposition->moves_to_stage_id;
            }
            if ($disposition->is_won) {
                $updates['status'] = 'won';
            } elseif ($disposition->is_lost) {
                $updates['status'] = 'lost';
            } else {
                $updates['status'] = 'working';
            }

            if ($nextFollowUpAt) {
                $updates['next_follow_up_at'] = $nextFollowUpAt;
                \App\Models\FollowUp::create([
                    'lead_id'        => $lead->id,
                    'user_id'        => Auth::id(),
                    'disposition_id' => $dispositionId,
                    'type'           => 'call',
                    'scheduled_at'   => $nextFollowUpAt,
                    'status'         => 'pending',
                ]);
            }

            $lead->update($updates);
            $lead->refresh();

            // Keep the patient facet on the same rung of the funnel.
            $this->patients->syncStageFromLead($lead);

            $this->logActivity($lead, 'status', 'Disposition Updated', $disposition->name . ': ' . $remark);

            return $lead;
        });
    }

    public function reassign(Lead $lead, int $toUserId, string $reason = ''): Lead
    {
        $previousUser = $lead->assigned_to;
        $lead->update([
            'assigned_to' => $toUserId,
            'assigned_at' => now(),
        ]);

        \App\Models\LeadAssignment::create([
            'lead_id'       => $lead->id,
            'assigned_to'   => $toUserId,
            'assigned_by'   => Auth::id(),
            'method'        => 'manual',
            'assigned_at'   => now(),
        ]);

        $fromName = $previousUser ? (\App\Models\User::find($previousUser)?->name ?? "#{$previousUser}") : 'nobody';
        $toName   = \App\Models\User::find($toUserId)?->name ?? "#{$toUserId}";

        $this->logActivity($lead, 'assignment', "Owner changed to {$toName}",
            "From {$fromName} to {$toName}" . ($reason !== '' ? ". Reason: {$reason}" : '.'));

        return $lead->refresh();
    }

    public function moveStage(Lead $lead, int $stageId): Lead
    {
        $lead->update(['stage_id' => $stageId, 'last_activity_at' => now()]);
        $this->logActivity($lead, 'status', 'Stage Changed', "Moved to stage #{$stageId}");
        return $lead->refresh();
    }

    /**
     * Every lead already has a patient record, so "converting" no longer
     * creates anything — it marks the couple as having reached the clinic.
     * Kept under the old name because call sites and reports read better this
     * way and the outward behaviour is unchanged.
     */
    public function convertToPatient(Lead $lead): Patient
    {
        $patient = $this->patients->ensureFor($lead);

        if (! $patient) {
            throw new \RuntimeException('Could not open a patient record for this enquiry.');
        }

        $lead->update(['status' => 'won', 'last_activity_at' => now()]);

        $this->patients->advance($patient, PatientJourney::CONSULTATION, 'converted from lead');

        $this->logActivity($lead, 'system', 'Reached consultation', "Patient {$patient->patient_code}");

        return $patient->refresh();
    }

    /**
     * Resolve the acquisition channel from the intake payload, falling back to
     * the configured lead source's own channel family.
     */
    private function resolveChannel(array $data): string
    {
        if (! empty($data['lead_source_id']) && empty($data['source_channel'])) {
            $data['source_channel'] = LeadSource::whereKey($data['lead_source_id'])->value('channel');
        }

        return AcquisitionChannel::detect($data);
    }

    private function mergeLead(Lead $existing, array $newData): Lead
    {
        $updates = array_filter($newData, fn($v) => !empty($v));

        // A returning enquiry must not silently overwrite the channel that won
        // the patient in the first place; keep the original attribution.
        unset($updates['channel']);

        $existing->update($updates + ['last_activity_at' => now()]);

        $this->patients->ensureFor($existing);

        $this->logActivity($existing, 'system', 'Lead Merged', 'Duplicate lead merged into this record');

        return $existing;
    }

    public function logActivity(Lead $lead, string $type, string $title, string $description = ''): void
    {
        LeadActivity::create([
            'lead_id'     => $lead->id,
            'user_id'     => Auth::id(),
            'type'        => $type,
            'title'       => $title,
            'description' => $description,
        ]);
    }
}
