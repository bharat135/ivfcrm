<?php

namespace App\Http\Controllers\Tata;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\TataAgent;
use App\Models\TataBroadcast;
use App\Models\TataDisposition;
use App\Models\TataNumber;
use App\Models\User;
use App\Services\Telephony\CallService;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\Tata\TataSyncService;
use App\Services\Telephony\TataException;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;

/**
 * Tata Teleservices → Overview: connection health, what is synced, live calls.
 */
class TataOverviewController extends Controller
{
    public function __construct(
        private SmartfloClient $tata,
        private TataSyncService $sync,
        private CallService $calls,
        private SettingsRepository $settings,
    ) {}

    public function index()
    {
        $today = CallLog::where('provider', 'tata')->whereDate('created_at', today());

        return view('tata.index', [
            'configured'     => $this->tata->isConfigured(),
            'provider'       => $this->calls->provider(),
            'webhookToken'   => (bool) $this->tata->webhookToken(),
            'callerId'       => $this->tata->callerId(),
            'lastSync'       => $this->settings->get('telephony', 'tata_last_sync_at'),
            'lastCdrSync'    => $this->settings->get('telephony', 'tata_last_cdr_sync_at'),
            'agents'         => [
                'linked'   => TataAgent::where('sync_status', 'linked')->count(),
                'unlinked' => TataAgent::where('sync_status', '!=', 'linked')->count(),
                'crmUsersWithoutAgent' => User::where('is_active', true)->whereDoesntHave('tataAgent')->count(),
            ],
            'dispositions'   => [
                'synced'  => TataDisposition::where('sync_status', 'synced')->count(),
                'pending' => TataDisposition::where('sync_status', '!=', 'synced')->count(),
                'total'   => \App\Models\Disposition::where('is_active', true)->count(),
            ],
            'numbers'        => TataNumber::count(),
            'broadcasts'     => TataBroadcast::whereIn('status', ['created', 'running', 'paused'])->count(),
            'callsToday'     => (clone $today)->count(),
            'connectedToday' => (clone $today)->connected()->count(),
            'pendingOutcome' => CallLog::where('provider', 'tata')->whereNull('disposition_id')->whereNotNull('ended_at')->count(),
            'unsynced'       => CallLog::where('provider', 'tata')->whereNotNull('disposition_id')->whereNull('tata_synced_at')->count(),
            'forceDisposition' => $this->calls->forceDisposition(),
        ]);
    }

    public function syncAll(): JsonResponse
    {
        if (! $this->tata->isConfigured()) {
            return response()->json(['success' => false, 'message' => 'Add the Smartflo credentials under Configuration first.'], 422);
        }

        $report = $this->sync->syncAll();

        $failed = collect($report)->filter(fn ($r) => ! $r['ok'])->keys();

        return response()->json([
            'success' => $failed->isEmpty(),
            'message' => $failed->isEmpty()
                ? 'Agents, numbers, departments, IVRs and dispositions are in sync with Smartflo.'
                : 'Sync finished with problems in: ' . $failed->implode(', ') . '.',
            'report'  => $report,
        ]);
    }

    /** Live calls on the Smartflo account, for the overview widget. */
    public function live(): JsonResponse
    {
        if (! $this->tata->isConfigured()) {
            return response()->json(['success' => false, 'calls' => [], 'message' => 'Not configured.']);
        }

        try {
            $calls = $this->tata->liveCalls();
        } catch (TataException $e) {
            return response()->json(['success' => false, 'calls' => [], 'message' => $e->displayMessage()]);
        }

        return response()->json([
            'success' => true,
            'calls'   => collect($calls)->map(fn ($c) => [
                'call_id'   => $c['call_id'] ?? $c['id'] ?? null,
                'direction' => $c['direction'] ?? null,
                'agent'     => $c['agent_name'] ?? $c['agent_number'] ?? null,
                'customer'  => $c['client_number'] ?? $c['customer_number'] ?? $c['call_to_number'] ?? null,
                'status'    => $c['status'] ?? $c['call_status'] ?? null,
                'since'     => $c['start_stamp'] ?? $c['time'] ?? null,
            ])->values(),
        ]);
    }
}
