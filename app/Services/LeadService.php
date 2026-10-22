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

                    // The same person enquiring again — often from a different
                    // channel — is a new touch on the existing lead, tracked so
                    // the earlier source is never overwritten.
                    $this->recordSourceTouch($existing, $data, isFirst: false);

                    return $existing;
                }
                if ($strategy === 'merge') {
                    return $this->mergeLead($duplicates->first(), $data);
                }
            }

            $lead = $this->repo->create(array_merge($data, [
                'lead_date' => $data['lead_date'] ?? now(),
            ]));

            $this->recordSourceTouch($lead, $data, isFirst: true);

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

            $subName = $subDispositionId ? \App\Models\Disposition::whereKey($subDispositionId)->value('name') : null;
            $followUpNote = $nextFollowUpAt
                ? ' · Next follow-up ' . \Carbon\Carbon::parse($nextFollowUpAt)->format('d-m-Y h:i A')
                : '';

            $this->logActivity($lead, 'status', 'Disposition added',
                $disposition->name . ($subName ? ' › ' . $subName : '') . ': ' . $remark . $followUpNote);

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

        $this->recordSourceTouch($existing, $newData, isFirst: false);

        $this->logActivity($existing, 'system', 'Lead Merged', 'Duplicate lead merged into this record');

        return $existing;
    }

    /**
     * Record one acquisition touch. Called for every enquiry — the first when a
     * lead is created, and each later time the same person comes back — so the
     * lead keeps its whole source/campaign journey, not just the first hit.
     */
    private function recordSourceTouch(Lead $lead, array $data, bool $isFirst): void
    {
        $channel = $data['channel'] ?? $this->resolveChannel($data);

        // Skip a repeat touch that is identical to the most recent one (the same
        // form submitted twice), so the history stays meaningful.
        if (! $isFirst) {
            $last = $lead->sourceTouches()->latest('occurred_at')->first();
            if ($last
                && $last->channel === $channel
                && (int) $last->campaign_id === (int) ($data['campaign_id'] ?? 0)
                && (string) $last->utm_campaign === (string) ($data['utm_campaign'] ?? '')) {
                return;
            }
        }

        \App\Models\LeadSourceTouch::create([
            'lead_id'          => $lead->id,
            'channel'          => $channel,
            'lead_source_id'   => $data['lead_source_id'] ?? $lead->lead_source_id,
            'campaign_id'      => $data['campaign_id'] ?? null,
            'adset_id'         => $data['adset_id'] ?? null,
            'ad_id'            => $data['ad_id'] ?? null,
            'utm_source'       => $data['utm_source'] ?? null,
            'utm_medium'       => $data['utm_medium'] ?? null,
            'utm_campaign'     => $data['utm_campaign'] ?? null,
            'external_lead_id' => $data['external_lead_id'] ?? null,
            'is_first'         => $isFirst,
            'occurred_at'      => now(),
            'raw'              => ['source_name' => $data['source_name'] ?? null],
        ]);

        if (! $isFirst) {
            $sourceName = $data['source_name'] ?? null;
            $this->logActivity($lead, 'system', 'Enquired again',
                'Re-enquiry via ' . AcquisitionChannel::label($channel) . ($sourceName ? ' (' . $sourceName . ')' : ''));
        }
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
