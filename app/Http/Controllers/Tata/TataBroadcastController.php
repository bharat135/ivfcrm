<?php

namespace App\Http\Controllers\Tata;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Disposition;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\TataAgent;
use App\Models\TataBroadcast;
use App\Models\TataDepartment;
use App\Models\TataIvr;
use App\Models\TataNumber;
use App\Models\User;
use App\Services\Telephony\Tata\TataBroadcastService;
use App\Services\Telephony\TataException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tata Teleservices → Broadcasts: dial a CRM lead segment through Smartflo.
 */
class TataBroadcastController extends Controller
{
    public function __construct(private TataBroadcastService $broadcasts) {}

    public function index()
    {
        return view('tata.broadcasts.index', [
            'broadcasts' => TataBroadcast::with('creator:id,name')->latest()->paginate(25),
        ]);
    }

    public function create()
    {
        return view('tata.broadcasts.create', $this->formData());
    }

    public function preview(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return response()->json(['success' => true, 'count' => $this->broadcasts->previewCount($filters)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                => ['required', 'string', 'max:120'],
            'description'         => ['nullable', 'string', 'max:255'],
            'destination_type'    => ['required', Rule::in(['ivr', 'department', 'agent'])],
            'destination_id'      => ['required', 'string', 'max:40'],
            'caller_id'           => ['required', 'string', 'regex:/^\+?[0-9]{6,15}$/'],
            'start_at'            => ['required', 'date'],
            'end_at'              => ['required', 'date', 'after:start_at'],
            'timeout'             => ['nullable', 'integer', 'min:40', 'max:120'],
            'concurrent_limit'    => ['nullable', 'integer', 'min:1', 'max:50'],
            'number_of_retry'     => ['nullable', 'integer', 'min:0', 'max:5'],
            'retry_after_minutes' => ['nullable', 'string', 'max:60'],
            'time_group'          => ['nullable', 'string', 'max:40'],
        ]);

        $filters = $this->filters($request);

        if ($this->broadcasts->previewCount($filters) === 0) {
            return response()->json(['success' => false, 'message' => 'The selected segment has no leads with a mobile number.'], 422);
        }

        $data['destination'] = $data['destination_type'] . '|' . $data['destination_id'];
        $data['retry_after_minutes'] = array_values(array_filter(array_map('intval', explode(',', (string) ($data['retry_after_minutes'] ?? '30')))));

        $broadcast = $this->broadcasts->create($data, $filters, $request->user());

        return response()->json([
            'success'  => $broadcast->status !== 'failed',
            'message'  => $broadcast->status === 'failed'
                ? ('Broadcast saved but not created on Smartflo: ' . $broadcast->last_error)
                : "Broadcast created on Smartflo with {$broadcast->lead_count} lead(s). Start it when you are ready.",
            'redirect' => route('tata.broadcasts.show', $broadcast),
        ], $broadcast->status === 'failed' ? 422 : 200);
    }

    public function show(TataBroadcast $broadcast)
    {
        return view('tata.broadcasts.show', [
            'broadcast' => $broadcast->load('creator:id,name'),
            'leads'     => $broadcast->leads()->with('lead:id,name,mobile')->latest('updated_at')->paginate(50),
        ]);
    }

    public function control(TataBroadcast $broadcast, string $action): JsonResponse
    {
        try {
            $this->broadcasts->control($broadcast, $action);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Broadcast ' . ($action === 'end' ? 'ended' : $action . 'ed') . '.']);
    }

    public function refresh(TataBroadcast $broadcast): JsonResponse
    {
        try {
            $broadcast = $this->broadcasts->refresh($broadcast);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Status refreshed from Smartflo: ' . $broadcast->status . '.',
            'stats'   => $broadcast->stats,
            'status'  => $broadcast->status,
        ]);
    }

    private function filters(Request $request): array
    {
        return array_filter($request->only([
            'stage_id', 'source_id', 'center_id', 'assigned_to', 'status', 'disposition_id',
            'created_from', 'created_to', 'lead_ids',
        ]), fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function formData(): array
    {
        return [
            'stages'       => PipelineStage::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'sources'      => LeadSource::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'centers'      => Center::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'users'        => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'dispositions' => Disposition::whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'numbers'      => TataNumber::orderByDesc('is_default')->orderBy('did')->get(),
            'ivrs'         => TataIvr::orderBy('name')->get(),
            'departments'  => TataDepartment::orderBy('name')->get(),
            'agents'       => TataAgent::whereNotNull('agent_id')->where('is_active', true)->orderBy('name')->get(),
        ];
    }
}
