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
                ->withCount('ads');

            return DataTables::of($query)
                ->addColumn('status_badge', fn (MetaLeadForm $f) =>
                    '<span class="badge bg-' . ($f->status === 'ACTIVE' ? 'success' : 'secondary') . '">' . e($f->status) . '</span>')
                ->addColumn('crm_leads', fn (MetaLeadForm $f) =>
                    Lead::whereJsonContains('extra->form_id', $f->external_id)->count())
                ->addColumn('routing', fn (MetaLeadForm $f) => e(collect([
                    $f->center?->name,
                    $f->assignee?->name,
                ])->filter()->join(' → ') ?: 'Auto-assign'))
                ->addColumn('synced', fn (MetaLeadForm $f) => $f->last_synced_at?->diffForHumans() ?? 'Never')
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
        ];
    }
}
