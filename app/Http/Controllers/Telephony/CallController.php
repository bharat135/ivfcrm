<?php

namespace App\Http\Controllers\Telephony;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\User;
use App\Services\Meta\MetaException;
use App\Services\Meta\WhatsAppCallingService;
use App\Services\Telephony\AsteriskManager;
use App\Services\Telephony\CallService;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\Tata\CallTranscriptService;
use App\Models\CallTranscript;
use App\Support\SettingsRepository;
use App\Services\Telephony\WhatsAppCallHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The agent-facing call console: click-to-call, in-call control, disposition,
 * presence, and the supervisor wallboard.
 *
 * Every endpoint returns JSON so the dialer widget in the topbar and the
 * click-to-call buttons on lead/patient pages share one implementation.
 */
class CallController extends Controller
{
    public function __construct(
        private CallService $calls,
        private AsteriskManager $ami,
        private SmartfloClient $tata,
        private WhatsAppCallHandler $whatsappCalls,
        private WhatsAppCallingService $calling,
    ) {}

    // -----------------------------------------------------------------
    // Console
    // -----------------------------------------------------------------

    public function console(Request $request)
    {
        $user = $request->user();

        $usesTata = $this->calls->usesTata();

        return view('telephony.console', [
            'extension'   => $user->sipExtension,
            'provider'    => $this->calls->provider(),
            'providerLabel' => $this->calls->providerLabel(),
            'usesTata'    => $usesTata,
            'canDial'     => $user->canPlaceCalls(),
            'dialIdentity'=> $user->dialIdentity(),
            'configured'  => $usesTata ? $this->tata->isConfigured() : $this->ami->isConfigured(),
            'dispositions'=> Disposition::whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->get(),
            'agents'      => User::where('is_active', true)
                ->where(fn ($q) => $usesTata ? $q->whereNotNull('tata_agent_number') : $q->whereHas('sipExtension'))
                ->orderBy('name')->get(['id', 'name']),
            'isSupervisor'=> $user->hasRole(['Super Admin', 'Admin', 'Management', 'Center Manager', 'Call Quality Analyst']),
        ]);
    }

    // -----------------------------------------------------------------
    // Placing calls
    // -----------------------------------------------------------------

    /**
     * Click-to-call over the PBX. Rings the agent's softphone first.
     */
    public function dial(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'     => ['nullable', 'string', 'max:20'],
            'lead_id'    => ['nullable', 'exists:leads,id'],
            'patient_id' => ['nullable', 'exists:patients,id'],
        ]);

        $lead    = ! empty($data['lead_id']) ? Lead::find($data['lead_id']) : null;
        $patient = ! empty($data['patient_id']) ? Patient::find($data['patient_id']) : null;

        $number = ($data['number'] ?? null) ?: $lead?->mobile ?: $patient?->mobile;

        if (! $number) {
            return response()->json([
                'success' => false,
                'message' => 'No phone number available for this contact.',
            ], 422);
        }

        try {
            $call = $this->calls->clickToCall(
                agent: $request->user(),
                destination: $number,
                lead: $lead,
                patient: $patient,
            );
        } catch (TelephonyException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->displayMessage(),
                'pending_call_id' => $e->raw['pending_call_id'] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $call->provider === 'tata'
                ? 'Your phone is ringing — answer it and Smartflo will connect the customer.'
                : 'Your softphone is ringing — answer it to connect the call.',
            'call'    => $this->payload($call),
        ]);
    }

    /**
     * Place a WhatsApp voice call. Requires an approved call permission.
     */
    public function dialWhatsApp(Request $request): JsonResponse
    {
        if (! $this->calling->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp calling is not enabled. Configure it under Settings → Telephony.',
            ], 422);
        }

        $data = $request->validate([
            'number'          => ['nullable', 'string', 'max:20'],
            'lead_id'         => ['nullable', 'exists:leads,id'],
            'conversation_id' => ['nullable', 'exists:conversations,id'],
        ]);

        $conversation = ! empty($data['conversation_id']) ? Conversation::find($data['conversation_id']) : null;
        $lead         = ! empty($data['lead_id']) ? Lead::find($data['lead_id']) : null;

        $number = ($data['number'] ?? null) ?: $conversation?->contact_identifier ?: $lead?->mobile;

        if (! $number) {
            return response()->json(['success' => false, 'message' => 'No WhatsApp number for this contact.'], 422);
        }

        $permission = $this->whatsappCalls->permissionFor($number, refresh: true);

        if (! $permission['allowed']) {
            return response()->json([
                'success'           => false,
                'message'           => $permission['reason'],
                'needs_permission'  => true,
            ], 422);
        }

        // With SIP routing configured, Meta delivers the call to Asterisk and
        // the dialplan rings the agent — the CRM only records the attempt.
        if (! config('telephony.whatsapp_calling.bridge.enabled')) {
            return response()->json([
                'success' => false,
                'message' => 'WhatsApp outbound calling needs a SIP server or media bridge configured under Settings → Telephony.',
            ], 422);
        }

        try {
            $response = $this->calling->connect($number, $request->input('sdp', ''));
        } catch (MetaException $e) {
            $this->whatsappCalls->logOutboundAttempt($number, Auth::id(), $conversation, null, ['error' => $e->displayMessage()]);

            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $call = $this->whatsappCalls->logOutboundAttempt(
            $number,
            Auth::id(),
            $conversation,
            $response['calls'][0]['id'] ?? null,
            $response,
        );

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp call placed.',
            'call'    => $this->payload($call),
        ]);
    }

    /**
     * Ask a contact, in-chat, for permission to call them on WhatsApp.
     */
    public function requestCallPermission(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'exists:conversations,id'],
        ]);

        $conversation = Conversation::findOrFail($data['conversation_id']);

        if (! $conversation->isWindowOpen()) {
            return response()->json([
                'success' => false,
                'message' => 'The 24-hour window is closed, so a call permission request cannot be sent. Send a template first.',
            ], 422);
        }

        try {
            $this->calling->requestPermission($conversation->contact_identifier, $conversation->channelAccount);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        \App\Models\WhatsappCallPermission::updateOrCreate(
            ['wa_id' => $conversation->contact_identifier],
            [
                'conversation_id' => $conversation->id,
                'lead_id'         => $conversation->lead_id,
                'patient_id'      => $conversation->patient_id,
                'requested_by'    => Auth::id(),
                'status'          => 'pending',
                'requested_at'    => now(),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Call permission requested — the contact will see it in the chat.',
        ]);
    }

    // -----------------------------------------------------------------
    // In-call control
    // -----------------------------------------------------------------

    public function hangup(Request $request, CallLog $call): JsonResponse
    {
        $this->authorizeCall($request, $call);

        try {
            if ($call->channel === 'whatsapp' && $call->wa_call_id) {
                $this->calling->terminate($call->wa_call_id);
                $this->calls->finalize($call, ['hangup_by' => 'agent']);
            } else {
                $this->calls->hangup($call);
            }
        } catch (TelephonyException | MetaException $e) {
            // The call may already be gone; close the record either way.
            $this->calls->finalize($call, ['hangup_by' => 'agent']);
        }

        return response()->json(['success' => true, 'message' => 'Call ended.']);
    }

    public function transfer(Request $request, CallLog $call): JsonResponse
    {
        $this->authorizeCall($request, $call);

        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);

        try {
            $child = $this->calls->transfer($call, User::findOrFail($data['user_id']));
        } catch (TelephonyException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Call transferred.',
            'call'    => $this->payload($child),
        ]);
    }

    /**
     * Supervisor listen / whisper / barge on a live agent call.
     */
    public function monitor(Request $request, CallLog $call): JsonResponse
    {
        abort_unless(
            $request->user()->hasRole(['Super Admin', 'Admin', 'Management', 'Center Manager', 'Call Quality Analyst']),
            403,
        );

        $data = $request->validate([
            'mode' => ['required', Rule::in(['listen', 'whisper', 'barge'])],
        ]);

        $supervisor = $request->user()->sipExtension;

        if (! $supervisor) {
            return response()->json([
                'success' => false,
                'message' => 'You need a SIP extension to monitor calls.',
            ], 422);
        }

        $channel = $call->meta['channel'] ?? null;

        if (! $channel && $call->extension) {
            $channel = $this->ami->findChannelForExtension($call->extension)['channel'] ?? null;
        }

        if (! $channel) {
            return response()->json(['success' => false, 'message' => 'That call is no longer live.'], 422);
        }

        try {
            $this->ami->chanSpy($supervisor->extension, $channel, $data['mode']);
        } catch (TelephonyException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your extension is ringing — answer to ' . $data['mode'] . '.',
        ]);
    }

    public function sendDtmf(Request $request, CallLog $call): JsonResponse
    {
        $this->authorizeCall($request, $call);

        $data = $request->validate([
            'digit' => ['required', 'string', 'size:1', 'regex:/^[0-9*#]$/'],
        ]);

        $channel = $call->meta['channel'] ?? null;

        if (! $channel) {
            return response()->json(['success' => false, 'message' => 'No live channel for this call.'], 422);
        }

        try {
            $this->ami->playDtmf($channel, $data['digit']);
        } catch (TelephonyException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    // -----------------------------------------------------------------
    // Disposition + logging
    // -----------------------------------------------------------------

    public function disposition(Request $request, CallLog $call): JsonResponse
    {
        $this->authorizeCall($request, $call);

        $data = $request->validate([
            'disposition_id'     => ['required', 'exists:dispositions,id'],
            'sub_disposition_id' => ['nullable', 'exists:dispositions,id'],
            'remark'             => ['nullable', 'string', 'max:2000'],
            'next_follow_up_at'  => ['nullable', 'date', 'after:now'],
        ]);

        $call = $this->calls->disposition($call, $data);

        return response()->json([
            'success'  => true,
            'message'  => 'Call outcome saved.',
            'call'     => $this->payload($call),
            // The profile page moves straight on to the next lead.
            'next_url' => route('leads.next', ['after' => $call->lead_id]),
        ]);
    }

    /**
     * Log a call that happened outside the PBX (mobile, walk-in follow-up).
     */
    public function logManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id'            => ['nullable', 'exists:leads,id'],
            'patient_id'         => ['nullable', 'exists:patients,id'],
            'direction'          => ['required', Rule::in(['inbound', 'outbound'])],
            'to_number'          => ['nullable', 'string', 'max:20'],
            'status'             => ['required', 'string', 'max:30'],
            'talk_seconds'       => ['nullable', 'integer', 'min:0', 'max:86400'],
            'disposition_id'     => ['nullable', 'exists:dispositions,id'],
            'sub_disposition_id' => ['nullable', 'exists:dispositions,id'],
            'remark'             => ['nullable', 'string', 'max:2000'],
            'next_follow_up_at'  => ['nullable', 'date'],
        ]);

        $call = CallLog::create([
            'lead_id'          => $data['lead_id'] ?? null,
            'patient_id'       => $data['patient_id'] ?? null,
            'user_id'          => Auth::id(),
            'center_id'        => $request->user()->center_id,
            'provider'         => 'manual',
            'channel'          => 'pstn',
            'direction'        => $data['direction'],
            'to_number'        => $data['to_number'] ?? null,
            'status'           => $data['status'],
            'talk_seconds'     => $data['talk_seconds'] ?? 0,
            'duration_seconds' => $data['talk_seconds'] ?? 0,
            'started_at'       => now(),
            'answered_at'      => ($data['talk_seconds'] ?? 0) > 0 ? now() : null,
            'ended_at'         => now(),
        ]);

        if (! empty($data['disposition_id'])) {
            $call = $this->calls->disposition($call, $data);
        }

        return response()->json([
            'success' => true,
            'message' => 'Call logged.',
            'call'    => $this->payload($call),
        ]);
    }

    // -----------------------------------------------------------------
    // Presence + live state
    // -----------------------------------------------------------------

    public function setStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['idle', 'break', 'dnd', 'offline'])],
        ]);

        $this->calls->setAgentStatus($request->user(), $data['status']);

        return response()->json([
            'success' => true,
            'message' => 'You are now ' . str_replace('_', ' ', $data['status']) . '.',
        ]);
    }

    /**
     * Polled by the topbar dialer widget.
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        $call = CallLog::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->live()->orWhere(fn ($w) => $w
                ->whereNull('disposition_id')
                ->where('ended_at', '>=', now()->subMinutes(10))))
            ->with(['lead:id,name,mobile', 'patient:id,wife_name,husband_name'])
            ->latest('id')
            ->first();

        return response()->json([
            'status'     => $user->call_status,
            'label'      => $user->callStatusLabel(),
            'badge'      => $user->callStatusBadge(),
            'extension'  => $user->dialIdentity(),
            'provider'   => $this->calls->provider(),
            'call'       => $call ? $this->payload($call) : null,
        ]);
    }

    /**
     * Supervisor wallboard.
     */
    public function board(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasRole(['Super Admin', 'Admin', 'Management', 'Center Manager', 'Call Quality Analyst']),
            403,
        );

        $centerId = $request->integer('center_id') ?: null;

        $liveCalls = CallLog::live()
            ->with(['user:id,name', 'lead:id,name'])
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->get()
            ->map(fn (CallLog $c) => [
                'id'        => $c->id,
                'agent'     => $c->user?->name,
                'contact'   => $c->contactName(),
                'number'    => $c->contactNumber(),
                'direction' => $c->direction,
                'channel'   => $c->channel,
                'status'    => $c->status,
                'started'   => $c->started_at?->diffForHumans(null, true),
            ]);

        return response()->json([
            'agents' => $this->calls->agentBoard($centerId),
            'calls'  => $liveCalls,
            'stats'  => [
                'live'      => $liveCalls->count(),
                'today'     => CallLog::whereDate('created_at', today())
                    ->when($centerId, fn ($q) => $q->where('center_id', $centerId))->count(),
                'connected' => CallLog::whereDate('created_at', today())->connected()
                    ->when($centerId, fn ($q) => $q->where('center_id', $centerId))->count(),
            ],
        ]);
    }

    /**
     * Recent calls for the console's history pane.
     */
    public function recent(Request $request): JsonResponse
    {
        $calls = CallLog::visibleTo($request->user())
            ->with(['lead:id,name', 'user:id,name', 'disposition:id,name,color'])
            ->latest('id')
            ->limit(25)
            ->get();

        return response()->json([
            'data' => $calls->map(fn (CallLog $c) => $this->payload($c)),
        ]);
    }

    // -----------------------------------------------------------------
    // Recordings
    // -----------------------------------------------------------------

    /**
     * Stream a recording from its private disk. Call audio is clinical data —
     * it is never exposed on a public URL.
     */
    public function recording(Request $request, CallLog $call): StreamedResponse
    {
        $this->authorizeCall($request, $call, allowSupervisor: true);

        // Provider-hosted recording (Smartflo): proxy it so call audio is only
        // reachable by a signed-in, authorised user, never via the raw URL.
        if (! $call->recording_path && $call->recording_url) {
            return $this->streamRemoteRecording($call);
        }

        abort_unless($call->recording_path, 404, 'No recording stored for this call.');

        $disk = Storage::disk($call->recording_disk ?: config('telephony.asterisk.recording.disk'));

        abort_unless($disk->exists($call->recording_path), 404, 'Recording file is no longer available.');

        return $disk->response(
            $call->recording_path,
            'call-' . $call->id . '.' . pathinfo($call->recording_path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, max-age=3600'],
            $request->boolean('download') ? 'attachment' : 'inline',
        );
    }

    /**
     * Stream a provider-hosted recording (Smartflo) through the CRM. A Smartflo
     * recording URL often needs the bearer token; we fetch server-side and
     * relay the bytes rather than redirecting the browser to the raw URL.
     */
    private function streamRemoteRecording(CallLog $call): StreamedResponse
    {
        $url = $call->recording_url;

        $token = null;
        if ($call->provider === 'tata' && str_contains($url, 'smartflo')) {
            try {
                $token = app(SmartfloClient::class)->accessToken();
            } catch (\Throwable) {
                $token = null;
            }
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
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function payload(CallLog $call): array
    {
        return [
            'id'            => $call->id,
            'direction'     => $call->direction,
            'channel'       => $call->channel,
            'channel_icon'  => $call->channelIcon(),
            'status'        => $call->status,
            'badge'         => $call->statusBadge(),
            'contact'       => $call->contactName(),
            'number'        => $call->contactNumber(),
            'lead_id'       => $call->lead_id,
            'lead_url'      => $call->lead_id ? route('leads.show', $call->lead_id) : null,
            'agent'         => $call->user?->name,
            'provider'      => $call->provider,
            'extension'     => $call->extension,
            'duration'      => $call->duration_formatted,
            'talk_seconds'  => $call->talk_seconds,
            'is_live'       => $call->isLive(),
            'needs_disposition' => ! $call->isLive() && ! $call->disposition_id,
            'disposition'   => $call->disposition?->name,
            'recording'     => $call->hasRecording() ? $call->recordingUrl() : null,
            'started_at'    => $call->started_at?->toIso8601String(),
            'started_human' => $call->started_at?->diffForHumans(null, true),
            'remark'        => $call->remark,
            'transcript_summary' => $call->relationLoaded('transcript') ? $call->transcript?->summary : null,
        ];
    }

    // -----------------------------------------------------------------
    // Live transcript + one-line note
    // -----------------------------------------------------------------

    /**
     * Whether the browser should capture a live transcript, and in which
     * language. Polled once by the dialer when a call goes live.
     */
    public function transcriptionConfig(Request $request): JsonResponse
    {
        $settings = app(SettingsRepository::class);

        return response()->json([
            'enabled'  => (bool) $settings->get('telephony', 'transcription_enabled', config('telephony.transcription.enabled')),
            'language' => (string) $settings->get('telephony', 'transcription_language', config('telephony.transcription.language')),
        ]);
    }

    /** Receive interim/final transcript segments captured in the browser. */
    public function appendTranscript(Request $request, CallLog $call, CallTranscriptService $transcripts): JsonResponse
    {
        $this->authorizeCall($request, $call);

        $data = $request->validate([
            'language'           => ['nullable', 'string', 'max:12'],
            'segments'           => ['required', 'array', 'max:100'],
            'segments.*.id'      => ['nullable', 'string', 'max:64'],
            'segments.*.t'       => ['nullable', 'numeric'],
            'segments.*.speaker' => ['nullable', 'string', 'max:20'],
            'segments.*.text'    => ['required', 'string', 'max:2000'],
            'segments.*.final'   => ['nullable', 'boolean'],
        ]);

        $transcripts->append($call, $data['segments'], 'browser', $data['language'] ?? null);

        return response()->json(['success' => true]);
    }

    /**
     * Close the transcript and return the suggested one-line note. The agent
     * edits it in the disposition modal before saving — transcripts are not
     * accurate enough to commit unreviewed.
     */
    public function summarizeTranscript(Request $request, CallLog $call, CallTranscriptService $transcripts): JsonResponse
    {
        $this->authorizeCall($request, $call);

        $data = $request->validate([
            'segments'         => ['nullable', 'array', 'max:100'],
            'segments.*.text'  => ['required_with:segments', 'string', 'max:2000'],
            'segments.*.final' => ['nullable', 'boolean'],
            'language'         => ['nullable', 'string', 'max:12'],
        ]);

        $transcript = $transcripts->finalize($call, $data['segments'] ?? [], $data['language'] ?? null);

        return response()->json([
            'success'    => true,
            'summary'    => $transcript->summary,
            'source'     => $transcript->summary_source,
            'transcript' => $transcript->transcript,
            'words'      => $transcript->wordCount(),
        ]);
    }

    /**
     * An agent may only act on their own calls; supervisors may listen to any.
     */
    private function authorizeCall(Request $request, CallLog $call, bool $allowSupervisor = false): void
    {
        $user = $request->user();

        if ($call->user_id === $user->id) {
            return;
        }

        $roles = $allowSupervisor
            ? ['Super Admin', 'Admin', 'Management', 'Center Manager', 'Call Quality Analyst']
            : ['Super Admin', 'Admin', 'Management', 'Center Manager'];

        abort_unless($user->hasRole($roles), 403, 'This call belongs to another agent.');
    }
}
