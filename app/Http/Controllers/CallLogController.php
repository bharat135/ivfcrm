<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\Center;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use App\Services\Telephony\CallService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class CallLogController extends Controller
{
    public function __construct(
        private LeadService $leadService,
        private CallService $calls,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = CallLog::with([
                    'lead:id,name,mobile',
                    'patient:id,wife_name,husband_name',
                    'user:id,name',
                    'disposition:id,name,color',
                ])
                ->visibleTo($request->user())
                ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
                ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->string('direction')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
                ->when($request->filled('center_id'), fn ($q) => $q->where('center_id', $request->integer('center_id')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')));

            return DataTables::of($query)
                ->addColumn('contact', fn (CallLog $c) => view('calllogs._contact', ['call' => $c])->render())
                ->addColumn('channel_badge', fn (CallLog $c) =>
                    '<span class="badge bg-light text-dark border"><i class="bi ' . $c->channelIcon() . ' me-1"></i>'
                    . e(ucfirst($c->channel)) . '</span>')
                ->addColumn('status_badge', fn (CallLog $c) =>
                    '<span class="badge bg-' . $c->statusBadge() . '">' . e($c->status ?: '—') . '</span>')
                ->addColumn('duration_fmt', fn (CallLog $c) => $c->duration_formatted)
                ->addColumn('ring_fmt', fn (CallLog $c) => $c->formatSeconds($c->ring_seconds))
                ->addColumn('action', fn (CallLog $c) => view('calllogs._actions', ['call' => $c])->render())
                ->rawColumns(['contact', 'channel_badge', 'status_badge', 'action'])
                ->make(true);
        }

        return view('calllogs.index', [
            'agents'       => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'centers'      => Center::where('is_active', true)->orderBy('name')->get(),
            'dispositions' => Disposition::whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    /**
     * Missed Smartflo calls. Opening the page clears the sidebar count: it
     * only counts what came in after the agent last looked.
     */
    public function missed(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $seenBefore = $request->date('seen');

            $query = CallLog::missed()
                ->missedVisibleTo($request->user())
                ->with(['lead:id,name,mobile', 'patient:id,wife_name,husband_name', 'user:id,name'])
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('started_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('started_at', '<=', $request->date('to')))
                ->select('call_logs.*');

            return DataTables::of($query)
                ->addColumn('contact', fn (CallLog $c) => view('calllogs._contact', ['call' => $c])->render()
                    . (! $seenBefore || $c->started_at?->gt($seenBefore) ? '<span class="badge bg-danger ms-4">New</span>' : ''))
                ->addColumn('agent', fn (CallLog $c) => e($c->user?->name ?? 'Unassigned'))
                ->addColumn('status_badge', fn (CallLog $c) =>
                    '<span class="badge bg-' . $c->statusBadge() . '">' . e($c->status ?: '—') . '</span>')
                ->addColumn('ring_fmt', fn (CallLog $c) => $c->formatSeconds($c->ring_seconds ?: $c->duration_seconds))
                ->addColumn('when', fn (CallLog $c) => $c->started_at?->format('d M Y, h:i A'))
                ->addColumn('action', fn (CallLog $c) => view('calllogs._actions', ['call' => $c])->render())
                ->rawColumns(['contact', 'status_badge', 'action'])
                ->make(true);
        }

        $user = $request->user();
        $seenBefore = $user->missed_calls_seen_at;
        $user->forceFill(['missed_calls_seen_at' => now()])->saveQuietly();

        return view('calllogs.missed', [
            'seenBefore' => $seenBefore,
            'agents'     => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(CallLog $call)
    {
        abort_unless(
            CallLog::visibleTo(Auth::user())->whereKey($call->id)->exists(),
            403,
        );

        $call->load(['lead', 'patient', 'user', 'disposition', 'subDisposition', 'events', 'transferredTo']);

        return view('calllogs.show', ['call' => $call]);
    }

    /**
     * Manually log a call that happened outside the PBX.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id'            => ['required', 'exists:leads,id'],
            'direction'          => ['required', 'in:inbound,outbound'],
            'status'             => ['required', 'string'],
            'duration_seconds'   => ['nullable', 'integer', 'min:0'],
            'recording_url'      => ['nullable', 'url'],
            'disposition_id'     => ['nullable', 'exists:dispositions,id'],
            'sub_disposition_id' => ['nullable', 'exists:dispositions,id'],
            'remark'             => ['nullable', 'string'],
            'provider_call_id'   => ['nullable', 'string'],
            'provider'           => ['nullable', 'string'],
            'started_at'         => ['nullable', 'date'],
        ]);

        $seconds = (int) ($data['duration_seconds'] ?? 0);

        $callLog = CallLog::create($data + [
            'user_id'      => Auth::id(),
            'center_id'    => Auth::user()->center_id,
            'channel'      => 'pstn',
            'provider'     => $data['provider'] ?? 'manual',
            'talk_seconds' => $seconds,
            'started_at'   => $data['started_at'] ?? now(),
            'answered_at'  => $seconds > 0 ? ($data['started_at'] ?? now()) : null,
            'ended_at'     => now(),
        ]);

        $lead = Lead::find($data['lead_id']);

        if ($lead) {
            $lead->increment('contact_attempts');
            $lead->update([
                'last_activity_at'   => now(),
                'first_contacted_at' => $lead->first_contacted_at ?? now(),
            ]);

            if (! empty($data['disposition_id'])) {
                $this->leadService->updateDisposition(
                    $lead,
                    $data['disposition_id'],
                    $data['sub_disposition_id'] ?? null,
                    $data['remark'] ?? '',
                    null,
                );
            }
        }

        return response()->json(['success' => true, 'id' => $callLog->id]);
    }

    /**
     * Legacy third-party telephony webhook (Exotel/Knowlarity/MyOperator).
     * Asterisk uses AsteriskWebhookController instead.
     */
    public function webhook(Request $request)
    {
        $provider = $request->route('provider');
        $payload  = $request->all();

        $callId = $payload['CallSid'] ?? $payload['call_id'] ?? null;

        if ($callId) {
            $seconds = (int) ($payload['Duration'] ?? $payload['duration'] ?? 0);

            CallLog::where('provider_call_id', $callId)->update([
                'provider'         => $provider,
                'status'           => $payload['Status'] ?? $payload['status'] ?? 'completed',
                'duration_seconds' => $seconds,
                'talk_seconds'     => $seconds,
                'recording_url'    => $payload['RecordingUrl'] ?? $payload['recording_url'] ?? null,
                'ended_at'         => now(),
            ]);
        }

        return response()->json(['received' => true]);
    }
}
