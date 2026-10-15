<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Center;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\MetaLeadForm;
use App\Models\User;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use App\Services\Meta\MetaSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;

/**
 * Instant (lead gen) forms.
 *
 * The CRM mirrors each form so a manager can decide, per form, which centre and
 * telecaller its leads route to and how Meta's question keys map onto CRM
 * fields — without touching the campaign itself.
 */
class LeadFormController extends Controller
{
    public function __construct(
        private MetaAdsService $ads,
        private MetaSyncService $sync,
        private MetaClient $client,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = MetaLeadForm::with(['source:id,name', 'center:id,name', 'assignee:id,name'])
                ->withCount('ads')
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('center_id'), fn ($q) => $q->where('center_id', $request->integer('center_id')))
                ->when($request->filled('campaign_id'), fn ($q) => $q->where('campaign_id', $request->integer('campaign_id')))
                ->when($request->filled('lead_source_id'), fn ($q) => $q->where('lead_source_id', $request->integer('lead_source_id')))
                ->when($request->filled('assign_to'), fn ($q) => $q->where('assign_to', $request->integer('assign_to')))
                ->when($request->filled('auto_sync'), fn ($q) => $q->where('auto_sync', $request->boolean('auto_sync')))
                ->when($request->filled('routing'), fn ($q) => $request->string('routing') == 'unrouted'
                    ? $q->whereNull('center_id')->whereNull('assign_to')
                    : $q->where(fn ($w) => $w->whereNotNull('center_id')->orWhereNotNull('assign_to')));

            return DataTables::of($query)
                ->addColumn('status_badge', fn (MetaLeadForm $f) =>
                    '<span class="badge bg-' . ($f->status === 'ACTIVE' ? 'success' : 'secondary') . '">' . e($f->status) . '</span>')
                ->addColumn('crm_leads', fn (MetaLeadForm $f) =>
                    Lead::whereJsonContains('extra->form_id', $f->external_id)->count())
                ->addColumn('routing', fn (MetaLeadForm $f) => e(collect([
                    $f->center?->name,
                    $f->assignee?->name,
                ])->filter()->join(' → ') ?: 'Auto-assign'))
                ->addColumn('synced', fn (MetaLeadForm $f) => $f->leads_synced_at?->diffForHumans() ?? 'Never')
                ->addColumn('action', fn (MetaLeadForm $f) => view('marketing.leadforms._actions', ['form' => $f])->render())
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        return view('marketing.leadforms.index', [
            'sources'    => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'centers'    => Center::where('is_active', true)->orderBy('name')->get(),
            'campaigns'  => Campaign::orderByDesc('id')->get(['id', 'name']),
            'agents'     => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'configured' => $this->client->isConfigured() && $this->client->pageId(),
            'crmFields'  => $this->crmFields(),
        ]);
    }

    /**
     * Pull the Page's forms into the CRM.
     */
    public function sync(Request $request): JsonResponse
    {
        try {
            $count = $this->sync->syncLeadForms($request->input('page_id'));
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Synced {$count} lead form(s) from Meta.",
        ]);
    }

    /**
     * Backfill submissions for one form (the webhook handles new ones live).
     */
    public function pullLeads(Request $request, MetaLeadForm $form): JsonResponse
    {
        $since = $request->filled('since') ? \Illuminate\Support\Carbon::parse($request->input('since')) : null;

        try {
            $created = $this->sync->syncFormLeads($form, $since);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $created > 0
                ? "Imported {$created} new lead(s)."
                : 'No new leads found for this period.',
        ]);
    }

    /**
     * Push a synthetic submission through exactly the path a real Lead Ads
     * submission takes (field map, campaign routing, dedup, assignment,
     * patient file), so routing can be checked without spending ad money.
     */
    public function testLead(Request $request, MetaLeadForm $form): JsonResponse
    {
        $stamp    = now()->format('dmy-His');
        $remoteId = 'test-' . $form->id . '-' . Str::lower(Str::random(8));
        $mobile   = '+919' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $campaign = $form->campaign_id ? Campaign::find($form->campaign_id) : null;

        $remote = [
            'id'           => $remoteId,
            'created_time' => now()->toIso8601String(),
            'form_id'      => $form->external_id,
            'campaign_id'  => $campaign?->external_id,
            'platform'     => 'facebook',
            'is_organic'   => false,
            'field_data'   => [
                ['name' => 'full_name',    'values' => ['Test Lead ' . $stamp]],
                ['name' => 'phone_number', 'values' => [$mobile]],
                ['name' => 'email',        'values' => ['test-' . $stamp . '@example.com']],
                ['name' => 'city',         'values' => ['Test City']],
            ],
        ];

        $created = $this->sync->importLead($remote, $form, ['is_test' => true]);
        $lead    = Lead::where('external_lead_id', $remoteId)->with(['assignedTo:id,name', 'center:id,name', 'source:id,name'])->first();

        if (! $created || ! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'The test submission did not turn into a lead. Check the field mapping (a phone question must map to Mobile) and the application log.',
            ], 422);
        }

        // Mark it unmistakably as a test so it can be filtered or deleted later.
        $lead->forceFill([
            'remark' => trim(($lead->remark ? $lead->remark . ' ' : '') . '[TEST LEAD created from lead form "' . $form->name . '" by ' . $request->user()->name . ']'),
            'extra'  => array_merge($lead->extra ?? [], ['is_test' => true, 'test_by' => $request->user()->id]),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => sprintf(
                'Test lead created: %s → %s, centre %s, assigned to %s, source %s.',
                $lead->name,
                $campaign ? 'campaign "' . $campaign->name . '"' : 'no campaign linked to this form',
                $lead->center?->name ?? 'none',
                $lead->assignedTo?->name ?? 'nobody (check Settings → Lead Assignment)',
                $lead->source?->name ?? 'none',
            ),
            'url' => route('leads.show', $lead),
        ]);
    }

    /**
     * Create a form directly on the Facebook Page.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:191'],
            'locale'             => ['nullable', 'string', 'max:20'],
            'privacy_policy_url' => ['required', 'url'],
            'questions'          => ['nullable', 'array'],
            'questions.*'        => ['string'],
            'intro_headline'     => ['nullable', 'string', 'max:191'],
            'intro_description'  => ['nullable', 'string', 'max:500'],
            'thank_you_title'    => ['nullable', 'string', 'max:191'],
            'thank_you_body'     => ['nullable', 'string', 'max:500'],
            'website_url'        => ['nullable', 'url'],
            'lead_source_id'     => ['nullable', 'exists:lead_sources,id'],
            'center_id'          => ['nullable', 'exists:centers,id'],
            'assign_to'          => ['nullable', 'exists:users,id'],
        ]);

        $questions = array_map(
            fn ($type) => ['type' => $type],
            $data['questions'] ?? ['FULL_NAME', 'PHONE', 'EMAIL'],
        );

        $payload = [
            'name'               => $data['name'],
            'locale'             => $data['locale'] ?? 'EN_US',
            'questions'          => $questions,
            'privacy_policy_url' => $data['privacy_policy_url'],
        ];

        if (! empty($data['intro_headline'])) {
            $payload['context_card'] = [
                'title'   => $data['intro_headline'],
                'style'   => 'LIST_STYLE',
                'content' => array_filter([$data['intro_description'] ?? null]),
                'button_text' => 'Continue',
            ];
        }

        if (! empty($data['thank_you_title'])) {
            $payload['thank_you_page'] = array_filter([
                'title'        => $data['thank_you_title'],
                'body'         => $data['thank_you_body'] ?? null,
                'button_type'  => 'VIEW_WEBSITE',
                'website_url'  => $data['website_url'] ?? null,
                'button_text'  => 'Visit our website',
            ]);
        }

        try {
            $response = $this->ads->createLeadForm($payload);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $form = MetaLeadForm::create([
            'external_id'        => $response['id'],
            'page_id'            => $this->client->pageId(),
            'name'               => $data['name'],
            'status'             => 'ACTIVE',
            'locale'             => $data['locale'] ?? 'EN_US',
            'questions'          => $questions,
            'privacy_policy_url' => $data['privacy_policy_url'],
            'lead_source_id'     => $data['lead_source_id'] ?? null,
            'center_id'          => $data['center_id'] ?? null,
            'assign_to'          => $data['assign_to'] ?? null,
            'last_synced_at'     => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead form created on your Facebook Page.',
            'id'      => $form->id,
        ]);
    }

    public function edit(MetaLeadForm $form): JsonResponse
    {
        return response()->json([
            'form'      => $form,
            'field_map' => $form->effectiveFieldMap(),
            'questions' => collect($form->questions ?? [])->map(fn ($q) => [
                'key'   => $q['key'] ?? $q['type'] ?? null,
                'label' => $q['label'] ?? $q['type'] ?? null,
                'type'  => $q['type'] ?? null,
            ]),
        ]);
    }

    /**
     * Routing + field mapping are CRM-side only; nothing is pushed to Meta.
     */
    public function update(Request $request, MetaLeadForm $form): JsonResponse
    {
        $data = $request->validate([
            'lead_source_id' => ['nullable', 'exists:lead_sources,id'],
            'campaign_id'    => ['nullable', 'exists:campaigns,id'],
            'center_id'      => ['nullable', 'exists:centers,id'],
            'assign_to'      => ['nullable', 'exists:users,id'],
            'auto_sync'      => ['boolean'],
            'field_map'      => ['nullable', 'array'],
            'field_map.*'    => ['nullable', 'string', 'max:60'],
        ]);

        $form->update([
            'lead_source_id' => $data['lead_source_id'] ?? null,
            'campaign_id'    => $data['campaign_id'] ?? null,
            'center_id'      => $data['center_id'] ?? null,
            'assign_to'      => $data['assign_to'] ?? null,
            'auto_sync'      => $request->boolean('auto_sync', true),
            'field_map'      => array_filter($data['field_map'] ?? []),
        ]);

        return response()->json(['success' => true, 'message' => 'Lead form settings saved.']);
    }

    public function destroy(MetaLeadForm $form): JsonResponse
    {
        // Only the local mirror is removed; the form stays live on the Page.
        $form->delete();

        return response()->json([
            'success' => true,
            'message' => 'Form removed from the CRM. It still exists on your Facebook Page.',
        ]);
    }

    /**
     * CRM lead attributes a Meta question can be mapped onto.
     */
    private function crmFields(): array
    {
        return [
            'name'       => 'Name',
            'mobile'     => 'Mobile',
            'email'      => 'Email',
            'gender'     => 'Gender',
            'age'        => 'Age',
            'city_name'  => 'City',
            'state_name' => 'State',
            'pincode'    => 'Pincode',
            'message'    => 'Remark / Message',
            'years_trying'  => 'Trying since (years)',
            'years_married' => 'Married since (years)',
            'wife_age'      => "Wife's age",
            'husband_age'   => "Husband's age",
        ];
    }
}
