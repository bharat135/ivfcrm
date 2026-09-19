<?php

namespace App\Http\Controllers;

use App\Models\Center;
use App\Models\Doctor;
use App\Models\LeadSource;
use App\Models\Patient;
use App\Models\User;
use App\Services\LeadService;
use App\Services\PatientLinker;
use App\Services\PatientProfile;
use App\Support\AcquisitionChannel;
use App\Support\PatientJourney;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

/**
 * The unified people list.
 *
 * A lead and a patient are the same person in this CRM, so this screen shows
 * everybody — the enquiry that came in ten minutes ago from a Google Ads
 * landing page sits in the same table as the couple in their second IVF cycle.
 * `journey_stage` is what separates them, not two different tables.
 */
class PatientController extends Controller
{
    public function __construct(
        private LeadService $leads,
        private PatientLinker $linker,
        private PatientProfile $profile,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return $this->datatable($request);
        }

        return view('patients.index', [
            'centers'  => Center::active()->get(),
            'sources'  => LeadSource::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'owners'   => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stages'   => PatientJourney::options(),
            'channels' => AcquisitionChannel::options(),
            'counts'   => $this->stageCounts($request),
        ]);
    }

    /**
     * Joined rather than eager-loaded: the acquisition facet has to be
     * sortable and searchable server-side, and these tables are sized for
     * lakhs of rows.
     */
    private function datatable(Request $request)
    {
        $query = Patient::query()
            ->leftJoin('leads', 'leads.id', '=', 'patients.lead_id')
            ->leftJoin('centers', 'centers.id', '=', 'patients.center_id')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->leftJoin('users', 'users.id', '=', 'leads.assigned_to')
            ->select([
                'patients.id',
                'patients.patient_code',
                'patients.wife_name',
                'patients.husband_name',
                'patients.mobile',
                'patients.email',
                'patients.journey_stage',
                'patients.status',
                'patients.first_enquiry_at',
                'patients.created_at',
                'patients.lead_id',
                'centers.name as center_name',
                'leads.channel as channel',
                'leads.status as lead_status',
                'leads.score_band as score_band',
                'leads.next_follow_up_at as next_follow_up_at',
                'leads.last_activity_at as last_activity_at',
                'lead_sources.name as source_name',
                'users.name as owner_name',
            ]);

        $this->scopeVisibility($query);
        $this->applyFilters($query, $request);

        return DataTables::of($query)
            ->addColumn('person', fn ($p) => view('patients._person', ['p' => $p])->render())
            ->addColumn('stage', fn ($p) => view('patients._stage', ['p' => $p])->render())
            ->addColumn('origin', fn ($p) => view('patients._origin', ['p' => $p])->render())
            ->editColumn('center_name', fn ($p) => $p->center_name ?: '—')
            ->editColumn('owner_name', fn ($p) => $p->owner_name ?: '<span class="text-muted">Unassigned</span>')
            ->editColumn('first_enquiry_at', fn ($p) => $this->date($p->first_enquiry_at)?->format('d M Y') ?: '—')
            ->addColumn('next_follow_up', function ($p) {
                $due = $this->date($p->next_follow_up_at);

                if (! $due) {
                    return '<span class="text-muted">—</span>';
                }

                $when = $due->format('d M, H:i');

                return $due->isPast()
                    ? '<span class="text-danger fw-semibold">' . $when . '</span>'
                    : '<span>' . $when . '</span>';
            })
            ->addColumn('action', fn ($p) => view('patients._actions', ['patient' => $p])->render())
            ->rawColumns(['person', 'stage', 'origin', 'owner_name', 'next_follow_up', 'action'])
            ->filterColumn('person', function ($query, $keyword) {
                $like = "%{$keyword}%";
                $query->where(function ($q) use ($like) {
                    $q->where('patients.wife_name', 'like', $like)
                        ->orWhere('patients.husband_name', 'like', $like)
                        ->orWhere('patients.mobile', 'like', $like)
                        ->orWhere('patients.email', 'like', $like)
                        ->orWhere('patients.patient_code', 'like', $like);
                });
            })
            ->orderColumn('person', 'patients.wife_name $1')
            ->orderColumn('stage', 'patients.journey_stage $1')
            ->orderColumn('origin', 'leads.channel $1')
            ->orderColumn('next_follow_up', 'leads.next_follow_up_at $1')
            ->make(true);
    }

    /**
     * Columns pulled in from the joined leads table arrive as raw strings —
     * Eloquent's cast map only covers the model's own attributes — so they are
     * parsed here rather than assumed to be Carbon instances.
     */
    private function date(mixed $value): ?\Illuminate\Support\Carbon
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? \Illuminate\Support\Carbon::instance($value)
            : \Illuminate\Support\Carbon::parse($value);
    }

    private function scopeVisibility($query): void
    {
        $user = Auth::user();

        if ($user->hasRole(['Super Admin', 'Admin', 'Management', 'Marketing Head'])) {
            return;
        }

        if ($user->hasRole('Center Manager')) {
            $query->where('patients.center_id', $user->center_id);

            return;
        }

        // A telecaller sees the people they own, from either facet.
        $query->where(function ($q) use ($user) {
            $q->where('leads.assigned_to', $user->id)
                ->orWhereNull('leads.assigned_to');
        });
    }

    private function applyFilters($query, Request $request): void
    {
        $query
            ->when($request->filled('stage'), fn ($q) => $q->where('patients.journey_stage', $request->stage))
            ->when($request->filled('channel'), fn ($q) => $q->where('leads.channel', $request->channel))
            ->when($request->filled('center_id'), fn ($q) => $q->where('patients.center_id', $request->center_id))
            ->when($request->filled('source_id'), fn ($q) => $q->where('leads.lead_source_id', $request->source_id))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('leads.assigned_to', $request->owner_id))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('patients.first_enquiry_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('patients.first_enquiry_at', '<=', $request->to))
            ->when($request->boolean('due'), fn ($q) => $q->whereNotNull('leads.next_follow_up_at')
                ->where('leads.next_follow_up_at', '<=', now()));
    }

    /**
     * The counts behind the stage chips. One grouped query, not six.
     *
     * @return array<string,int>
     */
    private function stageCounts(Request $request): array
    {
        $query = Patient::query()
            ->leftJoin('leads', 'leads.id', '=', 'patients.lead_id');

        $this->scopeVisibility($query);

        $counts = $query
            ->select('patients.journey_stage', DB::raw('count(*) as total'))
            ->groupBy('patients.journey_stage')
            ->pluck('total', 'journey_stage')
            ->all();

        $out = ['all' => array_sum($counts)];

        foreach (array_keys(PatientJourney::options()) as $stage) {
            $out[$stage] = (int) ($counts[$stage] ?? 0);
        }

        return $out;
    }

    public function show(Request $request, Patient $patient)
    {
        return view('patients.show', $this->profile->for($patient, $request->string('tab', 'overview')));
    }

    /**
     * Registering a patient by hand creates the enquiry too, so a walk-in
     * couple is attributed and reportable exactly like a paid lead.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:191'],
            'mobile'       => ['required', 'string', 'max:20'],
            'email'        => ['nullable', 'email', 'max:191'],
            'gender'       => ['nullable', 'in:male,female,other'],
            'age'          => ['nullable', 'integer', 'min:1', 'max:100'],
            'husband_name' => ['nullable', 'string', 'max:191'],
            'center_id'    => ['required', 'exists:centers,id'],
            'source_id'    => ['nullable', 'exists:lead_sources,id'],
        ]);

        $lead = $this->leads->create([
            'name'           => $data['name'],
            'mobile'         => preg_replace('/\D/', '', $data['mobile']),
            'email'          => $data['email'] ?? null,
            'gender'         => $data['gender'] ?? 'female',
            'age'            => $data['age'] ?? null,
            'center_id'      => $data['center_id'],
            'lead_source_id' => $data['source_id'] ?? null,
            'channel'        => AcquisitionChannel::WALK_IN,
            'lead_date'      => now(),
            'source_name'    => 'Front desk',
        ]);

        $patient = $this->linker->ensureFor($lead);

        if ($patient && ! empty($data['husband_name'])) {
            $patient->forceFill(['husband_name' => $data['husband_name']])->save();
        }

        // Somebody standing at the desk is further along than a cold enquiry.
        $this->linker->advance($patient, PatientJourney::CONSULTATION, 'registered at the front desk');

        return response()->json([
            'success'  => true,
            'id'       => $patient?->id,
            'redirect' => $patient ? route('patients.show', $patient) : route('patients.index'),
        ]);
    }

    public function update(Request $request, Patient $patient)
    {
        $data = $request->validate([
            'wife_name'                   => ['nullable', 'string', 'max:191'],
            'husband_name'                => ['nullable', 'string', 'max:191'],
            'mobile'                      => ['sometimes', 'string', 'max:20'],
            'alt_mobile'                  => ['nullable', 'string', 'max:20'],
            'email'                       => ['nullable', 'email', 'max:191'],
            // Ages and years are typed; dates are stored so they keep counting.
            'wife_age'                    => ['nullable', 'integer', 'min:1', 'max:100'],
            'husband_age'                 => ['nullable', 'integer', 'min:1', 'max:100'],
            'years_married'               => ['nullable', 'integer', 'min:0', 'max:80'],
            'years_trying'                => ['nullable', 'integer', 'min:0', 'max:60'],
            'address'                     => ['nullable', 'string'],
            'primary_doctor_id'           => ['nullable', 'exists:doctors,id'],
            'infertility_duration_months' => ['nullable', 'integer', 'min:0'],
            'previous_ivf'                => ['nullable', 'boolean'],
            'diagnosis'                   => ['nullable', 'string', 'max:255'],
            'treatment_notes'             => ['nullable', 'string'],
        ]);

        $patient->applyCoupleYears($data);
        $patient->fill([
            'wife_age'    => $patient->wife_age_years,
            'husband_age' => $patient->husband_age_years,
        ]);

        unset($data['wife_age'], $data['husband_age'], $data['years_married'], $data['years_trying']);

        $patient->fill($data)->save();
        $this->linker->syncCoupleDates($patient);

        return back()->with('success', 'Patient record updated.');
    }

    /**
     * Move the couple along the one funnel. The lead facet follows so the
     * telecaller worklist and the clinical list never disagree.
     */
    public function advance(Request $request, Patient $patient)
    {
        $data = $request->validate([
            'stage' => ['required', 'string', 'in:' . implode(',', array_keys(PatientJourney::options()))],
        ]);

        $this->linker->advance($patient, $data['stage'], 'changed by ' . Auth::user()->name);

        if ($lead = $patient->lead) {
            $lead->update([
                'status' => match ($data['stage']) {
                    PatientJourney::LOST                              => 'lost',
                    PatientJourney::TREATMENT, PatientJourney::CLOSED => 'won',
                    PatientJourney::CONSULTATION                      => 'won',
                    default                                           => 'working',
                },
                'last_activity_at' => now(),
            ]);

            $this->leads->logActivity(
                $lead,
                'status',
                'Journey stage changed',
                'Moved to ' . PatientJourney::label($data['stage'])
            );
        }

        return back()->with('success', 'Moved to ' . PatientJourney::label($data['stage']) . '.');
    }

    /**
     * All call recordings tied to this person: calls linked to their patient or
     * enquiry record, plus any call whose number matches one of their numbers.
     */
    public function recordings(Patient $patient)
    {
        $lead = $patient->lead;

        $numbers = collect([
            $patient->mobile, $patient->alt_mobile ?? null,
            $lead?->mobile, $lead?->alt_mobile,
        ])->filter()->map(fn ($n) => substr(preg_replace('/\D/', '', (string) $n), -10))
          ->filter(fn ($n) => strlen($n) === 10)->unique()->values();

        // Prefer a live read-through from the Smartflo CDR API (no storage) when
        // Tata is the active provider and configured.
        $calls = app(\App\Services\Telephony\CallService::class);
        $tata  = app(\App\Services\Telephony\SmartfloClient::class);

        if ($calls->usesTata() && $tata->isConfigured() && $numbers->isNotEmpty()) {
            $live = app(\App\Services\Telephony\Tata\TataSyncService::class)->liveRecordings($numbers->all());

            $recordings = collect($live)->map(fn (array $r) => [
                'id'          => $r['call_id'],
                'direction'   => $r['direction'],
                'number'      => $r['number'],
                'agent'       => $r['agent'],
                'disposition' => null,
                'duration'    => $r['duration'],
                'when'        => $r['when'],
                'url'         => route('tata.recording', ['u' => rtrim(strtr(base64_encode($r['recording_url']), '+/', '-_'), '=')]),
            ])->values();

            return response()->json(['success' => true, 'source' => 'smartflo', 'count' => $recordings->count(), 'recordings' => $recordings]);
        }

        $calls = \App\Models\CallLog::query()
            ->with(['user:id,name', 'disposition:id,name,color'])
            ->where(function ($q) use ($patient, $lead, $numbers) {
                $q->where('patient_id', $patient->id);
                if ($lead) {
                    $q->orWhere('lead_id', $lead->id);
                }
                foreach ($numbers as $tail) {
                    $q->orWhere('from_number', 'like', '%' . $tail)
                      ->orWhere('to_number', 'like', '%' . $tail);
                }
            })
            ->where(function ($q) {
                $q->whereNotNull('recording_url')->orWhereNotNull('recording_path');
            })
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->filter(fn (\App\Models\CallLog $c) => $c->hasRecording())
            ->map(fn (\App\Models\CallLog $c) => [
                'id'          => $c->id,
                'direction'   => $c->direction,
                'number'      => $c->contactNumber(),
                'agent'       => $c->user?->name,
                'disposition' => $c->disposition?->name,
                'duration'    => $c->duration_formatted,
                'when'        => $c->started_at?->format('d-m-Y h:i A'),
                'url'         => $c->recordingUrl(),
            ])
            ->values();

        return response()->json(['success' => true, 'source' => 'local', 'count' => $calls->count(), 'recordings' => $calls]);
    }

}
