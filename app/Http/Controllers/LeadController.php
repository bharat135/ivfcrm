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
                    'campaign:id,name',
                    // The list shows the patient code alongside the name; a lead
                    // and a patient are the same person.
                    'patient:id,patient_code,journey_stage']);

            $this->applyListFilters($query, $request);

            return DataTables::of($query)
                ->editColumn('mobile', fn (Lead $lead) => \App\Support\PhoneNumber::display($lead->mobile))
                // "Assigned To" is rendered from the assignedTo relation. Yajra
                // cannot map the snake_case data key back to that relation, so
                // it emitted `assigned_to.name` as a raw column and the whole
                // search query failed. Search and sort it explicitly.
                ->filterColumn('assigned_to.name', function ($q, $keyword) {
                    $q->whereHas('assignedTo', fn ($u) => $u->where('name', 'like', "%{$keyword}%"));
                })
                ->orderColumn('assigned_to.name', function ($q, $direction) {
                    $q->orderBy(User::select('name')->whereColumn('users.id', 'leads.assigned_to')->limit(1), $direction);
                })
                // A number typed with spaces, dashes or +91 still finds the lead.
                ->filterColumn('mobile', function ($q, $keyword) {
                    $digits = preg_replace('/\D+/', '', $keyword);
                    $needle = $digits !== '' ? \App\Support\PhoneNumber::indian($digits) : $keyword;
                    $q->where('mobile', 'like', "%{$needle}%");
                })
                ->addColumn('origin', fn (Lead $lead) => view('leads._origin', compact('lead'))->render())
                // Sub-source: the specific lead source name (Facebook form,
                // Website, referral…), a level below the channel in "Came from".
                ->addColumn('sub_source', fn (Lead $lead) => $lead->source?->name ?: ($lead->utm_source ?: '—'))
                ->addColumn('campaign_name', fn (Lead $lead) => $lead->campaign?->name ?: '—')
                ->editColumn('assigned_at', fn (Lead $lead) => $lead->assigned_at?->toIso8601String())
                ->editColumn('created_at', fn (Lead $lead) => $lead->created_at?->toIso8601String())
                ->filterColumn('sub_source', function ($q, $keyword) {
                    $q->where(function ($w) use ($keyword) {
                        $w->whereHas('source', fn ($s) => $s->where('name', 'like', "%{$keyword}%"))
                          ->orWhere('utm_source', 'like', "%{$keyword}%");
                    });
                })
                ->filterColumn('campaign_name', fn ($q, $keyword) => $q->whereHas('campaign', fn ($c) => $c->where('name', 'like', "%{$keyword}%")))
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
        $telecallers = User::active()->whereHas('roles', fn($q) => $q->whereIn('name', ['Telecaller', 'Counsellor', 'Call Quality Analyst']))->get();
        $channels    = AcquisitionChannel::options();

        return view('leads.index', compact('sources', 'stages', 'centers', 'telecallers', 'channels'));
    }

    /**
     * Navbar quick search: find a lead by its mobile number. Returns the profile
     * to open when found, or a signal to add a new lead (assigned to the
     * searcher) when not. Matches on the normalised ten digits.
     */
    public function quickSearch(Request $request)
    {
        $request->validate(['mobile' => ['required', 'string', 'max:20']]);

        $user   = $request->user();
        $digits = \App\Support\PhoneNumber::indian($request->mobile);

        if (! $digits || strlen($digits) < 10) {
            return response()->json(['found' => false, 'mobile' => $request->mobile, 'message' => 'Enter a 10-digit number.']);
        }

        $lead = Lead::query()
            ->whereNull('merged_into_id')
            ->where(fn ($q) => $q->where('dedup_hash', \App\Support\PhoneNumber::dedupHash($digits))
                ->orWhere('mobile', 'like', '%' . $digits)
                ->orWhere('alt_mobile', 'like', '%' . $digits))
            ->latest('id')
            ->first();

        if ($lead && $user->can('view', $lead)) {
            return response()->json([
                'found'   => true,
                'lead'    => ['id' => $lead->id, 'name' => $lead->name ?: 'Unnamed', 'owner' => $lead->assignedTo?->name],
                'url'     => route('leads.show', $lead),
            ]);
        }

        // Found but not visible to this agent, or not found at all: offer to add,
        // assigned to the searcher so it lands on their own worklist.
        return response()->json([
            'found'      => false,
            'mobile'     => $digits,
            'can_create' => $user->can('create', Lead::class),
            'create_url' => route('leads.create', ['mobile' => $digits]),
        ]);
    }

    /**
     * Navbar assigned-leads tray. An admin sees every new lead across the
     * telecallers and quality analysts, tagged with its owner; an owner sees
     * only their own. "New" means assigned but not yet called, so a lead drops
     * off the tray the moment its first call is logged.
     */
    public function assignedTray(Request $request)
    {
        $user    = $request->user();
        $isAdmin = $user->hasRole(['Super Admin', 'Admin', 'Management', 'Center Manager']);

        $query = Lead::query()
            ->whereNull('merged_into_id')
            ->where('status', 'new')
            ->where('contact_attempts', 0)
            ->whereNull('first_contacted_at')
            ->whereNotNull('assigned_to')
            ->with('assignedTo:id,name')
            ->latest('assigned_at');

        if ($isAdmin) {
            // Only leads owned by the calling roles, so the tray reflects the
            // people who work leads, not every user.
            $query->whereHas('assignedTo.roles', fn ($r) => $r->whereIn('name', ['Telecaller', 'Counsellor', 'Call Quality Analyst']));
            if ($user->hasRole('Center Manager') && $user->center_id) {
                $query->where('center_id', $user->center_id);
            }
        } else {
            $query->where('assigned_to', $user->id);
        }

        $count = (clone $query)->count();

        $leads = $query->limit(15)->get()->map(fn (Lead $lead) => [
            'id'    => $lead->id,
            'name'  => $lead->name ?: 'Unnamed lead',
            'owner' => $isAdmin ? $lead->assignedTo?->name : null,
            'when'  => $lead->assigned_at?->diffForHumans(),
            'url'   => route('leads.show', $lead),
        ]);

        return response()->json(['count' => $count, 'is_admin' => $isAdmin, 'leads' => $leads]);
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
        $telecallers = User::active()->whereHas('roles', fn($q) => $q->whereIn('name', ['Telecaller', 'Counsellor', 'Call Quality Analyst']))->get();
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

    /** Telecallers and quality analysts keep a lead's owner unless granted reassign_leads. */
    private function canReassign(User $user): bool
    {
        return $user->can('reassign_leads') || ! $user->hasRole(['Telecaller', 'Call Quality Analyst']);
    }

    private function isFrontLine(User $user): bool
    {
        return $user->hasRole(['Telecaller', 'Counsellor', 'Receptionist', 'Call Quality Analyst']);
    }

    /**
     * Change the centre straight from the profile, no edit screen. The
     * patient facet follows, and the change is on the log with who did it.
     */
    public function updateCenter(Request $request, Lead $lead)
    {
        $this->authorize('update', $lead);

        $data = $request->validate(['center_id' => ['required', 'exists:centers,id']]);

        $from = $lead->center?->name ?? 'none';
        $lead->update(['center_id' => (int) $data['center_id']]);
        $lead->load('center');

        if ($lead->patient) {
            $lead->patient->forceFill(['center_id' => $lead->center_id])->saveQuietly();
        }

        $this->service->logActivity($lead, 'system', 'Centre changed to ' . $lead->center->name, "From {$from} to {$lead->center->name}.");

        return response()->json(['success' => true, 'message' => 'Centre changed to ' . $lead->center->name . '.']);
    }

    /** "Centre: Ulwe → Thane; Name: A → B" for the log. */
    private function describeChanges(Lead $before, Lead $after): string
    {
        $labels = [
            'name' => 'Name', 'email' => 'Email', 'gender' => 'Gender', 'age' => 'Age', 'pincode' => 'Pincode',
            'center_id' => 'Centre', 'lead_source_id' => 'Source', 'assigned_to' => 'Owner', 'campaign_id' => 'Campaign',
            'remark' => 'Remark', 'lead_date' => 'Lead date', 'mobile' => 'Mobile', 'alt_mobile' => 'Alt. mobile',
            'wife_dob' => "Wife's DOB", 'husband_dob' => "Husband's DOB", 'marriage_date' => 'Marriage date', 'trying_since' => 'Trying since',
        ];

        $parts = [];
        foreach ($after->getChanges() as $key => $new) {
            if (! isset($labels[$key])) {
                continue;
            }

            $old = $before->getAttribute($key);
            $fmt = function ($v) use ($key) {
                if ($v === null || $v === '') return '—';
                if (in_array($key, ['mobile', 'alt_mobile'], true)) return \App\Support\PhoneNumber::display((string) $v);
                if ($key === 'center_id') return \App\Models\Center::whereKey($v)->value('name') ?? $v;
                if ($key === 'lead_source_id') return \App\Models\LeadSource::whereKey($v)->value('name') ?? $v;
                if ($key === 'assigned_to') return \App\Models\User::whereKey($v)->value('name') ?? $v;
                if ($key === 'campaign_id') return \App\Models\Campaign::whereKey($v)->value('name') ?? $v;
                if ($v instanceof \DateTimeInterface) return $v->format('d-m-Y');
                return (string) $v;
            };

            $parts[] = $labels[$key] . ': ' . $fmt($old) . ' → ' . $fmt($new);
        }

        return implode('; ', $parts);
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
        $telecallers = User::active()->whereHas('roles', fn($q) => $q->whereIn('name', ['Telecaller', 'Counsellor', 'Call Quality Analyst']))->get();

        $canReassign = $this->canReassign(auth()->user());

        return view('leads.edit', compact('lead', 'sources', 'centers', 'telecallers', 'canReassign'));
    }

    public function update(StoreLeadRequest $request, Lead $lead)
    {
        $this->authorize('update', $lead);

        $data = $this->withCoupleDates($request->validated(), $lead);

        // Telecallers only ever see a masked number, so they cannot change it.
        if (! \App\Support\PhoneNumber::canView()) {
            unset($data['mobile'], $data['alt_mobile']);
        }

        // Nor do they hand a lead to someone else from the edit form.
        if (! $this->canReassign($request->user())) {
            unset($data['assigned_to']);
        }

        $before = $lead->replicate();
        $lead->update($data);
        $this->linker->syncCoupleDates($lead);

        if ($lead->wasChanged('center_id') && $lead->patient) {
            $lead->patient->forceFill(['center_id' => $lead->center_id])->saveQuietly();
        }

        $this->service->logActivity($lead, 'system', 'Details updated',
            $this->describeChanges($before, $lead) ?: 'No field values changed.');

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

        // In a bulk-calling run the outcome moves the queue on and the next
        // lead is dialled; otherwise the usual "next lead" pick applies.
        $nextUrl = \App\Models\DialSession::advanceAfter($request->user(), $lead)
            ?? route('leads.next', ['after' => $lead->id]);

        // expectsJson() covers both jQuery (X-Requested-With) and API clients
        // that only send an Accept header.
        if ($request->expectsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Disposition updated.',
                'next_url' => $nextUrl,
            ]);
        }

        return redirect($nextUrl)->with('success', 'Disposition updated.');
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
