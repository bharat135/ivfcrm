<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Center;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Patient;
use App\Models\User;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowResponse;
use App\Services\Meta\Flows\FlowDispatcher;
use App\Services\Meta\Flows\FlowEncryption;
use App\Services\Meta\Flows\FlowResponseProcessor;
use App\Services\Meta\Flows\WhatsAppFlowService;
use App\Services\Meta\MetaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * Building, publishing and sending WhatsApp Flows, and reading what comes back.
 *
 * Publishing is one-way on Meta's side, so the CRM keeps the draft JSON locally
 * and only pushes on an explicit save; a published flow can then only be
 * deprecated and replaced.
 */
class FlowController extends Controller
{
    public function __construct(
        private WhatsAppFlowService $flows,
        private FlowDispatcher $dispatcher,
        private FlowEncryption $encryption,
        private FlowResponseProcessor $processor,
    ) {}

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = WhatsappFlow::with(['center:id,name', 'assignee:id,name'])
                ->withCount('responses')
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('purpose'), fn ($q) => $q->where('purpose', $request->string('purpose')));

            return DataTables::of($query)
                ->addColumn('status_badge', fn (WhatsappFlow $f) =>
                    '<span class="badge bg-' . $f->statusBadge() . '">' . e($f->status) . '</span>'
                    . ($f->isEndpointFlow() ? ' <span class="badge bg-light text-dark border">Endpoint</span>' : ''))
                ->addColumn('purpose_label', fn (WhatsappFlow $f) =>
                    e(config("whatsapp_flows.starters.{$f->purpose}.label", ucfirst(str_replace('_', ' ', $f->purpose)))))
                ->addColumn('completion', fn (WhatsappFlow $f) => $f->completionRate() . '%')
                ->addColumn('action', fn (WhatsappFlow $f) => view('marketing.flows._actions', ['flow' => $f])->render())
                ->rawColumns(['status_badge', 'action'])
                ->make(true);
        }

        return view('marketing.flows.index', [
            'starters'   => config('whatsapp_flows.starters'),
            'categories' => config('whatsapp_flows.categories'),
            'sources'    => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'campaigns'  => Campaign::orderByDesc('id')->get(['id', 'name']),
            'centers'    => Center::where('is_active', true)->orderBy('name')->get(),
            'agents'     => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'configured' => $this->flows->isConfigured(),
            'hasKeyPair' => $this->encryption->hasKeyPair(),
            'endpointUrl'=> url('/api/webhooks/whatsapp-flow'),
        ]);
    }

    public function show(Request $request, WhatsappFlow $flow)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = $flow->responses()->with(['lead:id,name,mobile', 'appointment:id,scheduled_at']);

            return DataTables::of($query)
                ->addColumn('contact', fn (WhatsappFlowResponse $r) => $r->lead
                    ? '<a href="' . route('leads.show', $r->lead_id) . '">' . e($r->lead->name ?: $r->wa_id) . '</a>'
                    : e($r->wa_id ?: '—'))
                ->addColumn('summary_text', fn (WhatsappFlowResponse $r) => e($r->summary(4)))
                ->addColumn('status_badge', fn (WhatsappFlowResponse $r) =>
                    '<span class="badge bg-' . $r->statusBadge() . '">' . e($r->status) . '</span>')
                ->addColumn('action', fn (WhatsappFlowResponse $r) =>
                    '<button class="btn btn-xs btn-outline-secondary js-view-response" data-id="' . $r->id . '">'
                    . '<i class="bi bi-eye"></i></button>')
                ->rawColumns(['contact', 'status_badge', 'action'])
                ->make(true);
        }

        $flow->load(['center', 'assignee', 'source']);

        return view('marketing.flows.show', [
            'flow'      => $flow,
            'crmFields' => config('whatsapp_flows.crm_fields'),
            'stats'     => [
                'sent'      => $flow->sent_count,
                'responses' => $flow->response_count,
                'rate'      => $flow->completionRate(),
                'pending'   => $flow->sessions()->whereNotIn('status', ['completed', 'failed', 'expired'])->count(),
            ],
        ]);
    }

    public function responseDetail(WhatsappFlowResponse $response): JsonResponse
    {
        return response()->json([
            'answers'     => $response->answers,
            'status'      => $response->status,
            'error'       => $response->error,
            'received_at' => $response->created_at->format('d M Y, g:i A'),
            'lead_url'    => $response->lead_id ? route('leads.show', $response->lead_id) : null,
        ]);
    }

    // -----------------------------------------------------------------
    // Building
    // -----------------------------------------------------------------

    /**
     * Create locally from a starter. Nothing is pushed to Meta until the flow
     * is saved, so an admin can adjust the JSON first.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:191'],
            'purpose'              => ['required', Rule::in(array_keys(config('whatsapp_flows.starters')) + ['custom' => 'custom'])],
            'description'          => ['nullable', 'string', 'max:500'],
            'categories'           => ['nullable', 'array'],
            'categories.*'         => [Rule::in(array_keys(config('whatsapp_flows.categories')))],
            'lead_source_id'       => ['nullable', 'exists:lead_sources,id'],
            'campaign_id'          => ['nullable', 'exists:campaigns,id'],
            'center_id'            => ['nullable', 'exists:centers,id'],
            'assign_to'            => ['nullable', 'exists:users,id'],
            'auto_create_lead'     => ['boolean'],
            'auto_book_appointment'=> ['boolean'],
        ]);

        $starter = config("whatsapp_flows.starters.{$data['purpose']}");

        if (! $starter && $data['purpose'] !== 'custom') {
            return response()->json(['success' => false, 'message' => 'Unknown flow template.'], 422);
        }

        $isEndpoint = ($starter['mode'] ?? 'navigate') === 'data_exchange';

        $flow = WhatsappFlow::create([
            'name'             => $data['name'],
            'description'      => $data['description'] ?? ($starter['description'] ?? null),
            'purpose'          => $data['purpose'],
            'status'           => 'DRAFT',
            'categories'       => $data['categories'] ?? ($starter['categories'] ?? ['OTHER']),
            'json_version'     => config('whatsapp_flows.json_version'),
            'data_api_version' => $isEndpoint ? config('whatsapp_flows.data_api_version') : null,
            'endpoint_uri'     => $isEndpoint ? url('/api/webhooks/whatsapp-flow') : null,
            'flow_json'        => json_encode($starter['json'] ?? ['version' => config('whatsapp_flows.json_version'), 'screens' => []],
                                              JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'field_map'        => $starter['field_map'] ?? [],
            'lead_source_id'   => $data['lead_source_id'] ?? null,
            'campaign_id'      => $data['campaign_id'] ?? null,
            'center_id'        => $data['center_id'] ?? null,
            'assign_to'        => $data['assign_to'] ?? null,
            'auto_create_lead' => $request->boolean('auto_create_lead', true),
            'auto_book_appointment' => $request->boolean('auto_book_appointment', $isEndpoint),
            'created_by'       => Auth::id(),
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Draft created. Review the JSON, then publish it to Meta.',
            'redirect' => route('marketing.flows.show', $flow),
        ]);
    }

    /**
     * Save routing, mapping and (for a draft) the Flow JSON.
     */
    public function update(Request $request, WhatsappFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'name'                  => ['required', 'string', 'max:191'],
            'description'           => ['nullable', 'string', 'max:500'],
            'flow_json'             => ['nullable', 'string'],
            'field_map'             => ['nullable', 'array'],
            'field_map.*'           => ['nullable', 'string', 'max:60'],
            'lead_source_id'        => ['nullable', 'exists:lead_sources,id'],
            'campaign_id'           => ['nullable', 'exists:campaigns,id'],
            'center_id'             => ['nullable', 'exists:centers,id'],
            'assign_to'             => ['nullable', 'exists:users,id'],
            'auto_create_lead'      => ['boolean'],
            'auto_book_appointment' => ['boolean'],
        ]);

        if (! empty($data['flow_json'])) {
            if ($flow->isPublished()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A published flow is frozen on Meta. Deprecate it and create a new version to change the JSON.',
                ], 422);
            }

            if (json_decode($data['flow_json'], true) === null) {
                return response()->json(['success' => false, 'message' => 'The Flow JSON is not valid JSON.'], 422);
            }
        }

        $flow->update(array_filter([
            'name'           => $data['name'],
            'description'    => $data['description'] ?? null,
            'flow_json'      => $data['flow_json'] ?? null,
            'field_map'      => array_filter($data['field_map'] ?? []),
            'lead_source_id' => $data['lead_source_id'] ?? null,
            'campaign_id'    => $data['campaign_id'] ?? null,
            'center_id'      => $data['center_id'] ?? null,
            'assign_to'      => $data['assign_to'] ?? null,
        ], fn ($v) => $v !== null) + [
            'auto_create_lead'      => $request->boolean('auto_create_lead'),
            'auto_book_appointment' => $request->boolean('auto_book_appointment'),
        ]);

        return response()->json(['success' => true, 'message' => 'Flow saved.']);
    }

    /**
     * Push to Meta: create the Flow object if needed, then upload the JSON.
     * Meta validates on upload, which is the only way to surface a bad screen
     * short of publishing.
     */
    public function push(WhatsappFlow $flow): JsonResponse
    {
        if ($flow->isPublished()) {
            return response()->json([
                'success' => false,
                'message' => 'This flow is already published and can no longer be changed.',
            ], 422);
        }

        try {
            if (! $flow->external_id) {
                $created = $this->flows->createFlow(
                    $flow->name,
                    $flow->categories ?: ['OTHER'],
                    $flow->endpoint_uri,
                    $flow->channelAccount,
                );

                $flow->update(['external_id' => $created['id'] ?? null]);
            } else {
                $this->flows->updateFlowMetadata($flow->external_id, [
                    'name'         => $flow->name,
                    'categories'   => $flow->categories,
                    'endpoint_uri' => $flow->endpoint_uri,
                ], $flow->channelAccount);
            }

            $result = $this->flows->uploadFlowJson($flow->external_id, $flow->decodedJson(), $flow->channelAccount);
        } catch (MetaException $e) {
            $flow->update(['sync_error' => $e->displayMessage()]);

            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $flow->update([
            'validation_errors' => $result['validation_errors'] ?: null,
            'synced_at'         => now(),
            'sync_error'        => null,
        ]);

        if ($result['validation_errors']) {
            return response()->json([
                'success' => false,
                'message' => 'Meta rejected the Flow JSON. See the validation errors below.',
                'errors'  => $result['validation_errors'],
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Flow uploaded to Meta and validated.']);
    }

    public function publish(WhatsappFlow $flow): JsonResponse
    {
        if (! $flow->external_id) {
            return response()->json(['success' => false, 'message' => 'Upload the flow to Meta first.'], 422);
        }

        if ($flow->isEndpointFlow() && ! $this->encryption->hasKeyPair()) {
            return response()->json([
                'success' => false,
                'message' => 'This flow uses an endpoint, so generate and upload the encryption key pair before publishing.',
            ], 422);
        }

        try {
            $this->flows->publish($flow->external_id, $flow->channelAccount);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $flow->update(['status' => 'PUBLISHED', 'published_at' => now(), 'synced_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Flow published. It can now be sent, and its JSON is frozen.',
        ]);
    }

    public function deprecate(WhatsappFlow $flow): JsonResponse
    {
        try {
            $this->flows->deprecate($flow->external_id, $flow->channelAccount);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $flow->update(['status' => 'DEPRECATED']);

        return response()->json(['success' => true, 'message' => 'Flow deprecated.']);
    }

    public function preview(WhatsappFlow $flow): JsonResponse
    {
        if (! $flow->external_id) {
            return response()->json(['success' => false, 'message' => 'Upload the flow to Meta to preview it.'], 422);
        }

        try {
            $url = $this->flows->preview($flow->external_id, false, $flow->channelAccount);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $flow->update(['preview_url' => $url, 'preview_expires_at' => now()->addDays(30)]);

        return response()->json(['success' => true, 'url' => $url]);
    }

    public function destroy(WhatsappFlow $flow): JsonResponse
    {
        if ($flow->external_id && $flow->isDraft()) {
            try {
                $this->flows->deleteFlow($flow->external_id, $flow->channelAccount);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        } elseif ($flow->isPublished()) {
            return response()->json([
                'success' => false,
                'message' => 'A published flow cannot be deleted. Deprecate it instead.',
            ], 422);
        }

        $flow->delete();

        return response()->json(['success' => true, 'message' => 'Flow deleted.']);
    }

    public function sync(): JsonResponse
    {
        try {
            $count = $this->flows->sync();
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => "Synced {$count} flow(s) from Meta."]);
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    public function send(Request $request, WhatsappFlow $flow): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'exists:conversations,id'],
            'lead_id'         => ['nullable', 'exists:leads,id'],
            'patient_id'      => ['nullable', 'exists:patients,id'],
            'mobile'          => ['nullable', 'string', 'max:20'],
            'cta'             => ['nullable', 'string', 'max:20'],
            'body'            => ['nullable', 'string', 'max:1024'],
            'header'          => ['nullable', 'string', 'max:60'],
            'footer'          => ['nullable', 'string', 'max:60'],
        ]);

        $options = array_filter([
            'cta'    => $data['cta'] ?? null,
            'body'   => $data['body'] ?? null,
            'header' => $data['header'] ?? null,
            'footer' => $data['footer'] ?? null,
        ]);

        try {
            if (! empty($data['conversation_id'])) {
                $message = $this->dispatcher->send(
                    $flow,
                    Conversation::findOrFail($data['conversation_id']),
                    $options,
                    Auth::id(),
                );
            } else {
                $message = $this->dispatcher->sendToContact(
                    flow: $flow,
                    lead: ! empty($data['lead_id']) ? Lead::find($data['lead_id']) : null,
                    patient: ! empty($data['patient_id']) ? Patient::find($data['patient_id']) : null,
                    mobile: $data['mobile'] ?? null,
                    options: $options,
                    userId: Auth::id(),
                );
            }
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success'    => true,
            'message'    => 'Flow sent.',
            'message_id' => $message->id,
        ]);
    }

    /**
     * Re-run the mapping over a stored submission, after a field map is fixed.
     */
    public function reprocess(WhatsappFlowResponse $response): JsonResponse
    {
        $this->processor->process($response);

        return response()->json([
            'success' => true,
            'message' => 'Response reprocessed.',
            'status'  => $response->fresh()->status,
        ]);
    }

    // -----------------------------------------------------------------
    // Endpoint encryption keys
    // -----------------------------------------------------------------

    public function generateKeys(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403);

        $this->encryption->generateKeyPair();

        return response()->json([
            'success'     => true,
            'message'     => 'Key pair generated. Upload the public key to Meta to activate endpoint flows.',
            'fingerprint' => $this->encryption->publicKeyFingerprint(),
        ]);
    }

    public function uploadKey(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403);

        try {
            $this->flows->uploadPublicKey();
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Public key uploaded to Meta.']);
    }

    public function keyStatus(): JsonResponse
    {
        $local = $this->encryption->publicKeyFingerprint();

        $remote = null;
        try {
            $remote = $this->flows->registeredPublicKey();
        } catch (MetaException $e) {
            // Not configured yet is a normal state, not an error to surface.
        }

        return response()->json([
            'success'      => true,
            'has_key_pair' => $this->encryption->hasKeyPair(),
            'fingerprint'  => $local,
            'meta_status'  => $remote['business_public_key_signature_status'] ?? null,
            'endpoint_url' => url('/api/webhooks/whatsapp-flow'),
        ]);
    }
}
