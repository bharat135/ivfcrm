<?php

namespace App\Http\Controllers;

use App\Http\Requests\Lead\StoreLeadRequest;
use App\Models\Center;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\User;
use App\Repositories\LeadRepository;
use App\Services\LeadService;
use App\Services\PatientLinker;
use App\Services\PatientProfile;
use App\Support\AcquisitionChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class LeadController extends Controller
{
    public function __construct(
        private LeadService $service,
        private LeadRepository $repo,
        private PatientLinker $linker,
        private PatientProfile $profile,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = $this->repo->queryForUser(Auth::user())
                ->with(['source:id,name', 'stage:id,name,color', 'disposition:id,name,color',
                    'assignedTo:id,name', 'center:id,name,code',
                    // The list shows the patient code alongside the name; a lead
                    // and a patient are the same person.
                    'patient:id,patient_code,journey_stage']);

            $this->applyListFilters($query, $request);

            return DataTables::of($query)
                ->addColumn('origin', fn (Lead $lead) => view('leads._origin', compact('lead'))->render())
                ->addColumn('action', function (Lead $lead) {
                    return view('leads._actions', compact('lead'))->render();
                })
                ->addColumn('stage_badge', fn($l) =>
                    $l->stage ? "<span class='badge' style='background:{$l->stage->color}'>{$l->stage->name}</span>" : '—'
                )
                ->addColumn('disposition_badge', fn($l) =>
                    $l->disposition ? "<span class='badge' style='background:{$l->disposition->color}'>{$l->disposition->name}</span>" : '—'
                )
                ->rawColumns(['action', 'stage_badge', 'disposition_badge', 'origin'])
                ->make(true);
        }

        $sources     = LeadSource::active()->get();
        $stages      = PipelineStage::active()->get();
        $centers     = Center::active()->get();
        $telecallers = User::active()->whereHas('roles', fn($q) => $q->whereIn('name', ['Telecaller', 'Counsellor']))->get();
        $channels    = AcquisitionChannel::options();

        return view('leads.index', compact('sources', 'stages', 'centers', 'telecallers', 'channels'));
    }

    /**
     * The filter bar sends everything as query parameters; the DataTables
     * search box is handled separately by Yajra.
     */
    private function applyListFilters($query, Request $request): void
    {
        $query
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->channel))
            ->when($request->filled('source_id'), fn ($q) => $q->where('lead_source_id', $request->source_id))
            ->when($request->filled('stage_id'), fn ($q) => $q->where('stage_id', $request->stage_id))
            ->when($request->filled('center_id'), fn ($q) => $q->where('center_id', $request->center_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('assigned_to'), fn ($q) => $q->where('assigned_to', $request->assigned_to));
    }

    /**
     * Open whatever the agent should work on next (see NextLeadFinder), or
     * fall back to the worklist when nothing is waiting.
     */
    public function next(Request $request, \App\Services\NextLeadFinder $finder)
    {
        $next = $finder->for($request->user(), $request->integer('after') ?: null);

        if (! $next) {
            return redirect()->route('leads.index')->with('success', 'Nothing waiting: no uncalled leads and no follow-ups due right now.');
        }

        return redirect()->route('leads.show', $next['lead'])->with('success', 'Next: ' . $next['reason']);
    }

    public function create()
    {
        $sources     = LeadSource::active()->get();
        $centers     = Center::active()->get();
        $telecallers = User::active()->whereHas('roles', fn($q) => $q->whereIn('name', ['Telecaller', 'Counsellor']))->get();
        $stages      = PipelineStage::active()->get();

        return view('leads.create', compact('sources', 'centers', 'telecallers', 'stages'));
    }

    public function store(StoreLeadRequest $request)
    {
        $user = Auth::user();
        $data = $request->validated();

        if (empty($data['center_id'])) {
            $data['center_id'] = $user->center_id;
        }

        // A front-line user entering an enquiry is the one talking to that
        // person, so the lead is theirs unless they hand it to a colleague.
        // Auto-assignment would otherwise route it to whoever is least loaded
        // and the creator could no longer even open it.
        if (empty($data['assigned_to']) && $this->isFrontLine($user)) {
            $data['assigned_to'] = $user->id;
        }

        $data['source_name'] = 'manual entry by ' . $user->name;
        $data = $this->withCoupleDates($data);

        $lead = $this->service->create($data);

        // Dedup can hand back somebody else's existing enquiry; sending the
        // user to a record they cannot see would be a 403.
        if ($user->cannot('view', $lead)) {
            $lead->loadMissing('assignedTo');
            $owner = $lead->assignedTo?->name ?? 'another team';

            return redirect()->route('leads.index')
                ->with('warning', "An enquiry with this mobile or email already exists and is handled by {$owner}. Nothing new was created.");
        }

        return redirect()->route('leads.show', $lead)
            ->with('success', 'Lead created successfully.');
    }

    private function isFrontLine(User $user): bool
    {
        return $user->hasRole(['Telecaller', 'Counsellor', 'Receptionist']);
    }

    /**
     * The form asks for ages and years; the lead stores dates so they keep
     * counting. The legacy single `age` follows the wife's (or husband's) age
     * so older screens and imports keep working.
     */
    private function withCoupleDates(array $data, ?Lead $existing = null): array
    {
        $probe = $existing ? $existing->replicate() : new Lead();
        $dates = $probe->applyCoupleYears($data);

        foreach ($dates as $key => $value) {
            $data[$key] = $value?->toDateString();
        }

        if (empty($data['age'])) {
            $years = $probe->wife_age_years ?? $probe->husband_age_years;
            if ($years) {
                $data['age'] = $years;
            }
        }

        unset($data['wife_age'], $data['husband_age'], $data['years_married'], $data['years_trying']);

        return $data;
    }

    /**
     * An enquiry and a patient are the same person, so /leads/{id} renders the
     * one profile screen rather than a second, subtly different view of them.
     */
    public function show(Request $request, Lead $lead)
    {
        $this->authorize('view', $lead);

        $profile = $this->profile->forLead($lead, $request->string('tab', 'overview'));

        if (! $profile) {
            return redirect()
                ->route('leads.index')
                ->with('error', 'This enquiry has no patient record and one could not be opened.');
        }

        return view('patients.show', $profile);
    }

    public function edit(Lead $lead)
    {
        $this->authorize('update', $lead);
        $sources  = LeadSource::active()->get();
        $centers  = Center::active()->get();
        $telecallers = User::active()->whereHas('roles', fn($q) => $q->whereIn('name', ['Telecaller', 'Counsellor']))->get();

        return view('leads.edit', compact('lead', 'sources', 'centers', 'telecallers'));
    }

    public function update(StoreLeadRequest $request, Lead $lead)
    {
        $this->authorize('update', $lead);
        $lead->update($this->withCoupleDates($request->validated(), $lead));
        $this->linker->syncCoupleDates($lead);
        $this->service->logActivity($lead, 'system', 'Lead Updated', 'Lead details updated by ' . Auth::user()->name);

        return redirect()->route('leads.show', $lead)->with('success', 'Lead updated.');
    }

    public function destroy(Lead $lead)
    {
        $this->authorize('delete', $lead);
        $lead->delete();
        return redirect()->route('leads.index')->with('success', 'Lead deleted.');
    }

    public function updateDisposition(Request $request, Lead $lead)
    {
        $request->validate([
            'disposition_id'     => ['required', 'exists:dispositions,id'],
            'sub_disposition_id' => ['nullable', 'exists:dispositions,id'],
            'remark'             => ['required', 'string', 'min:3'],
            'next_follow_up_at'  => ['nullable', 'date', 'after:now', new \App\Rules\WithinCallHours()],
        ]);

        $this->service->updateDisposition(
            $lead,
            $request->disposition_id,
            $request->sub_disposition_id,
            $request->remark,
            $request->next_follow_up_at,
        );

        // "Log a call" from the profile is how an agent dispositions the call
        // they just made. Stamp that agent's most recent un-dispositioned call
        // for this lead so the mandatory-disposition gate is satisfied and the
        // outcome is mirrored to the provider (Tata).
        $pendingCall = \App\Models\CallLog::where('lead_id', $lead->id)
            ->where('user_id', $request->user()->id)
            ->whereNull('disposition_id')
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', now()->subHours(48))
            ->latest('ended_at')
            ->first();

        if ($pendingCall) {
            app(\App\Services\Telephony\CallService::class)->disposition($pendingCall, [
                'disposition_id'     => $request->disposition_id,
                'sub_disposition_id' => $request->sub_disposition_id,
                'remark'             => $request->remark,
                'next_follow_up_at'  => $request->next_follow_up_at,
            ], syncLead: false);
        }

        // expectsJson() covers both jQuery (X-Requested-With) and API clients
        // that only send an Accept header.
        if ($request->expectsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Disposition updated.',
                'next_url' => route('leads.next', ['after' => $lead->id]),
            ]);
        }

        return redirect()->route('leads.next', ['after' => $lead->id])->with('success', 'Disposition updated.');
    }

    public function reassign(Request $request, Lead $lead)
    {
        $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'reason'  => ['nullable', 'string'],
        ]);

        $this->service->reassign($lead, $request->user_id, $request->reason ?? '');

        return response()->json(['success' => true]);
    }

    public function bulkAssign(Request $request)
    {
        $request->validate([
            'lead_ids' => ['required', 'array'],
            'user_id'  => ['required', 'exists:users,id'],
        ]);

        Lead::whereIn('id', $request->lead_ids)->each(function (Lead $lead) use ($request) {
            $this->service->reassign($lead, $request->user_id, 'Bulk assignment');
        });

        return response()->json(['success' => true, 'count' => count($request->lead_ids)]);
    }

    public function stageUpdate(Request $request, Lead $lead)
    {
        $request->validate(['stage_id' => ['required', 'exists:pipeline_stages,id']]);
        $this->service->moveStage($lead, $request->stage_id);
        return response()->json(['success' => true]);
    }
}
