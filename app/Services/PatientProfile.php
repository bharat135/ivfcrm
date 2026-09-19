<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Disposition;
use App\Models\Doctor;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\User;
use App\Support\PatientJourney;

/**
 * Everything the person profile screen renders.
 *
 * A lead and a patient are one record, so `/leads/{id}` and `/patients/{id}`
 * show the same screen. The payload lives here rather than in either controller
 * so the two routes can never drift into showing different things about the
 * same human being.
 */
class PatientProfile
{
    public function __construct(private PatientLinker $linker) {}

    /**
     * @return array<string,mixed>  View data for `patients.show`.
     */
    public function for(Patient $patient, string $tab = 'overview'): array
    {
        $patient->load([
            'center', 'primaryDoctor', 'city', 'state',
            'lead.source', 'lead.assignedTo', 'lead.stage', 'lead.disposition',
            'lead.campaign', 'lead.adset', 'lead.ad',
            'documents.uploader',
            'appointments' => fn ($q) => $q->with('doctor:id,name')->latest('scheduled_at')->limit(25),
            'treatmentCycles' => fn ($q) => $q->with('doctor:id,name')->latest()->limit(10),
        ]);

        $lead = $patient->lead;

        // Everything that happened to this person is logged against the enquiry
        // facet, because that is the record a telecaller works against.
        $timeline = $lead
            ? $lead->activities()->with('user:id,name')->limit(50)->get()
            : collect();

        $calls = $lead
            ? $lead->callLogs()->with(['user:id,name', 'disposition:id,name,color'])->limit(25)->get()
            : collect();

        $conversations = Conversation::query()
            ->where('patient_id', $patient->id)
            ->when($patient->lead_id, fn ($q) => $q->orWhere('lead_id', $patient->lead_id))
            ->with('latestMessage')
            ->latest('last_message_at')
            ->limit(10)
            ->get();

        return [
            'patient'       => $patient,
            'lead'          => $lead,
            'tasks'         => $this->taskBoard($lead),
            'timeline'      => $timeline,
            'calls'         => $calls,
            'conversations' => $conversations,
            'cycle'         => $patient->treatmentCycles->first(),
            'nextVisit'     => $patient->appointments
                ->where('scheduled_at', '>=', now())
                ->sortBy('scheduled_at')
                ->first(),
            'doctors'      => Doctor::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'centers'      => \App\Models\Center::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'dispositions' => Disposition::active()->primary()->with('children')->get(),
            'telecallers'  => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages'       => PatientJourney::options(),
            'activeTab'    => $tab,
        ];
    }

    /**
     * The same profile, reached from the enquiry side.
     *
     * An old row that predates unification, or one whose link was lost, gets a
     * patient opened here rather than falling through to a 404.
     */
    public function forLead(Lead $lead, string $tab = 'overview'): ?array
    {
        $patient = $lead->patient ?: $this->linker->ensureFor($lead);

        return $patient ? $this->for($patient, $tab) : null;
    }

    /**
     * Follow-ups, grouped the way a telecaller reads them.
     *
     * Deliberately not grouped by the raw status column: a call booked for next
     * week and one that was due yesterday share a status but are completely
     * different jobs, and only one of them is urgent.
     *
     * @return array<string,array{label:string,tone:string,items:\Illuminate\Support\Collection}>
     */
    private function taskBoard(?Lead $lead): array
    {
        $followUps = $lead
            ? $lead->followUps()->with(['user:id,name', 'disposition:id,name,color'])->get()
            : collect();

        $pending = $followUps->where('status', 'pending');

        $groups = [
            'overdue' => [
                'label' => 'Due now',
                'tone'  => 'overdue',
                'items' => $pending
                    ->filter(fn ($f) => $f->scheduled_at && $f->scheduled_at->isPast())
                    ->sortBy('scheduled_at')
                    ->values(),
            ],
            'todo' => [
                'label' => 'Upcoming',
                'tone'  => 'todo',
                'items' => $pending
                    ->filter(fn ($f) => ! $f->scheduled_at || $f->scheduled_at->isFuture())
                    ->sortBy('scheduled_at')
                    ->values(),
            ],
            'done' => [
                'label' => 'Done',
                'tone'  => 'done',
                'items' => $followUps->where('status', 'done')->sortByDesc('scheduled_at')->values(),
            ],
            'missed' => [
                'label' => 'Missed',
                'tone'  => 'missed',
                'items' => $followUps->where('status', 'missed')->sortByDesc('scheduled_at')->values(),
            ],
        ];

        // An empty "Missed" column is noise. An empty "Due now" is good news and
        // worth showing, so only the exception groups are dropped.
        return array_filter(
            $groups,
            fn ($group, $key) => $group['items']->isNotEmpty() || in_array($key, ['overdue', 'todo'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
