<?php

namespace App\Http\Controllers\Tata;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\Tata\TataSyncService;
use App\Services\Telephony\TataException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

/**
 * Tata Teleservices → Calls: the Smartflo call ledger with recordings,
 * outcomes, and their push-back state; plus CDR backfill.
 */
class TataCallController extends Controller
{
    public function __construct(private TataSyncService $sync) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = CallLog::where('provider', 'tata')
                ->with(['user:id,name', 'lead:id,name', 'disposition:id,name,color'])
                ->select('call_logs.*');

            return DataTables::of($query)
                ->addColumn('contact', fn (CallLog $c) => e($c->contactName()) . '<div class="small text-muted">' . e($c->contactNumber()) . '</div>')
                ->addColumn('agent', fn (CallLog $c) => e($c->user?->name ?? '—'))
                ->addColumn('outcome', fn (CallLog $c) => $c->disposition
                    ? '<span class="badge" style="background:' . e($c->disposition->color ?: '#6c757d') . '">' . e($c->disposition->name) . '</span>'
                    : '<span class="badge bg-warning-subtle text-warning">No outcome</span>')
                ->addColumn('note', fn (CallLog $c) => e($c->remark ? \Illuminate\Support\Str::limit($c->remark, 60) : '—'))
                ->addColumn('duration_fmt', fn (CallLog $c) => $c->duration_formatted)
                ->addColumn('recording', fn (CallLog $c) => $c->hasRecording()
                    ? '<button class="btn btn-sm btn-outline-secondary js-play" data-src="' . e($c->recordingUrl()) . '"><i class="bi bi-play-fill"></i></button>'
                    : '<span class="text-muted small">—</span>')
                ->addColumn('synced', fn (CallLog $c) => $this->syncBadge($c))
                ->addColumn('when', fn (CallLog $c) => $c->started_at?->format('d M Y, H:i'))
                ->rawColumns(['contact', 'outcome', 'recording', 'synced'])
                ->make(true);
        }

        return view('tata.calls', [
            'total'     => CallLog::where('provider', 'tata')->count(),
            'connected' => CallLog::where('provider', 'tata')->connected()->count(),
            'pending'   => CallLog::where('provider', 'tata')->whereNull('disposition_id')->whereNotNull('ended_at')->count(),
            'unsynced'  => CallLog::where('provider', 'tata')->whereNotNull('disposition_id')->whereNull('tata_synced_at')->count(),
        ]);
    }

    public function pull(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDay();
        $to   = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now();

        if ($from->diffInDays($to) > 31) {
            return response()->json(['success' => false, 'message' => 'Please pull at most 31 days at a time.'], 422);
        }

        try {
            $result = $this->sync->pullCallRecords($from, $to);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Fetched {$result['fetched']} record(s): {$result['created']} new call(s), {$result['updated']} updated with recordings/durations.",
        ]);
    }

    public function resync(CallLog $call): JsonResponse
    {
        try {
            $ok = $this->sync->pushCallOutcome($call);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Outcome pushed to Smartflo.' : ($call->tata_sync_error ?: 'Nothing to push — the call has no outcome yet.'),
        ]);
    }

    private function syncBadge(CallLog $call): string
    {
        if (! $call->disposition_id) {
            return '<span class="text-muted small">—</span>';
        }

        if ($call->tata_synced_at) {
            return '<span class="badge bg-success-subtle text-success">Synced</span>';
        }

        return '<span class="badge bg-warning-subtle text-warning" title="' . e($call->tata_sync_error ?? '') . '">Pending</span>';
    }

    /**
     * Stream a Smartflo-hosted recording (fetched live from the CDR API, not
     * stored) through the CRM, adding the bearer token and keeping the raw URL
     * off the page. The URL is base64 in `u` and must point at a Smartflo host.
     */
    public function recording(Request $request, SmartfloClient $tata): StreamedResponse
    {
        $u = strtr((string) $request->query('u'), '-_', '+/');
        $url = base64_decode($u, true);
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        abort_unless($url && $host && filter_var($url, FILTER_VALIDATE_URL), 404, 'Invalid recording link.');

        // SSRF guard: only Smartflo / Tata hosts.
        $allowed = ['smartflo', 'tatatele', 'tatateleservices'];
        abort_unless(collect($allowed)->contains(fn ($n) => str_contains(strtolower($host), $n)), 403, 'Recording host not allowed.');

        try {
            $token = $tata->accessToken();
        } catch (\Throwable) {
            $token = null;
        }

        return response()->stream(function () use ($url, $token) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => $token ? ['Authorization: Bearer ' . $token] : [],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }, 200, [
            'Content-Type'  => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=1800',
        ]);
    }

}
