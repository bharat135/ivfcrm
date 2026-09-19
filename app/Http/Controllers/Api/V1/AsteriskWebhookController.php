<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\SipExtension;
use App\Services\Telephony\AsteriskManager;
use App\Services\Telephony\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Call events pushed from the Asterisk dialplan.
 *
 * PHP-FPM cannot hold a persistent AMI event socket, so instead of listening
 * for events the dialplan posts them here with CURL(). Add to extensions.conf:
 *
 *   [globals]
 *   CRM_URL=https://crm.example.com/api/webhooks/asterisk
 *   CRM_TOKEN=<the token from Settings → Telephony>
 *
 *   [macro-crm-event]
 *   exten => s,1,Set(CURL_RESULT=${CURL(${CRM_URL}/${ARG1},token=${CRM_TOKEN}
 *       &channel_id=${UNIQUEID}&linked_id=${LINKEDID}&channel=${CHANNEL}
 *       &from_number=${CALLERID(num)}&to_number=${EXTEN}&crm_call_id=${CRM_CALL_ID}
 *       &token_var=${CRM_TOKEN_VAR}&extension=${CRM_EXTEN})})
 *
 * Every endpoint is idempotent: Asterisk may retry, and a duplicate event must
 * not double-count talk time or create a second log row.
 */
class AsteriskWebhookController extends Controller
{
    public function __construct(
        private CallService $calls,
        private AsteriskManager $ami,
    ) {}

    /**
     * @param  string  $event  ringing|answered|hangup|inbound|transfer|recording|registration
     */
    public function handle(Request $request, string $event): JsonResponse
    {
        if (! $this->authorized($request)) {
            Log::warning('Asterisk webhook rejected: bad token', [
                'ip'    => $request->ip(),
                'event' => $event,
            ]);

            return response()->json(['error' => 'unauthorized'], 403);
        }

        $data = $this->normalize($request);

        try {
            $result = match ($event) {
                'inbound'      => $this->inbound($data),
                'ringing'      => $this->ringing($data),
                'answered'     => $this->answered($data),
                'hangup'       => $this->hangup($data),
                'recording'    => $this->recording($data),
                'registration' => $this->registration($data),
                default        => $this->unknown($event, $data),
            };
        } catch (\Throwable $e) {
            Log::error('Asterisk webhook failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            // Always 200: the dialplan must not stall on a CRM error, and the
            // raw event is already persisted for replay.
            return response()->json(['received' => true, 'error' => $e->getMessage()]);
        }

        return response()->json(['received' => true] + $result);
    }

    // -----------------------------------------------------------------
    // Events
    // -----------------------------------------------------------------

    private function inbound(array $data): array
    {
        // A retry of the same channel must not open a second log.
        if (! empty($data['channel_id'])) {
            $existing = CallLog::where('channel_id', $data['channel_id'])->first();

            if ($existing) {
                return ['call_id' => $existing->id, 'duplicate' => true];
            }
        }

        $call = $this->calls->recordInbound($data);

        $this->calls->recordEvent($call, 'asterisk', 'inbound', $data['channel_id'] ?? null, $data);

        return [
            'call_id' => $call->id,
            // Handed back so the dialplan can route to the lead's owner.
            'agent_extension' => $call->user?->sipExtension?->extension,
            'lead_id'         => $call->lead_id,
            'contact'         => $call->contactName(),
        ];
    }

    private function ringing(array $data): array
    {
        $call = $this->calls->locate($data);

        if (! $call) {
            return ['matched' => false];
        }

        $this->calls->markRinging($call, $data);
        $this->calls->recordEvent($call, 'asterisk', 'ringing', $data['channel_id'] ?? null, $data);

        return ['call_id' => $call->id];
    }

    private function answered(array $data): array
    {
        $call = $this->calls->locate($data);

        if (! $call) {
            return ['matched' => false];
        }

        if ($call->answered_at) {
            return ['call_id' => $call->id, 'duplicate' => true];
        }

        $this->calls->markAnswered($call, $data);
        $this->calls->recordEvent($call, 'asterisk', 'answered', $data['channel_id'] ?? null, $data);

        return ['call_id' => $call->id];
    }

    private function hangup(array $data): array
    {
        $call = $this->calls->locate($data);

        if (! $call) {
            return ['matched' => false];
        }

        if ($call->ended_at) {
            return ['call_id' => $call->id, 'duplicate' => true];
        }

        $cause = $data['hangup_cause'] ?? null;

        $this->calls->finalize($call, array_filter([
            'timestamp'        => $data['timestamp'] ?? null,
            'talk_seconds'     => isset($data['billsec']) ? (int) $data['billsec'] : null,
            'duration_seconds' => isset($data['duration']) ? (int) $data['duration'] : null,
            'hangup_cause'     => $cause,
            'status'           => $this->statusFromCause($cause, $call),
            'recording_path'   => $data['recording_path'] ?? null,
        ], fn ($v) => $v !== null));

        $this->calls->recordEvent($call, 'asterisk', 'hangup', $data['channel_id'] ?? null, $data);

        return ['call_id' => $call->id];
    }

    /**
     * MixMonitor finished — attach the file to the call.
     */
    private function recording(array $data): array
    {
        $call = $this->calls->locate($data);

        if (! $call) {
            return ['matched' => false];
        }

        $path = $data['recording_path'] ?? $data['file'] ?? null;

        if ($path) {
            $call->update([
                'recording_path' => $path,
                'recording_disk' => config('telephony.asterisk.recording.disk'),
                'is_recorded'    => true,
            ]);
        }

        $this->calls->recordEvent($call, 'asterisk', 'recording', $data['channel_id'] ?? null, $data);

        return ['call_id' => $call->id, 'recording' => (bool) $path];
    }

    /**
     * Softphone registration state, so the settings screen can show who is
     * actually online without polling AMI on every page load.
     */
    private function registration(array $data): array
    {
        $extension = $data['extension'] ?? null;

        if (! $extension) {
            return ['matched' => false];
        }

        $status = $data['status'] ?? 'unknown';

        SipExtension::where('extension', $extension)->update([
            'registration_status' => $status,
            'last_registered_at'  => str_contains(strtolower($status), 'reachable')
                || strtolower($status) === 'registered' ? now() : null,
        ]);

        return ['extension' => $extension, 'status' => $status];
    }

    private function unknown(string $event, array $data): array
    {
        $call = $this->calls->locate($data);

        $this->calls->recordEvent($call, 'asterisk', $event, $data['channel_id'] ?? null, $data);

        return ['logged' => true];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Shared-secret auth. The dialplan cannot do CSRF or Sanctum, so a constant
     * -time token comparison is the pragmatic control here — combined with
     * restricting the endpoint to the PBX's IP at the firewall.
     */
    private function authorized(Request $request): bool
    {
        $expected = $this->ami->webhookToken();

        if (! $expected) {
            // No token configured yet — refuse rather than accept anything.
            return false;
        }

        $provided = $request->input('token')
            ?? $request->header('X-Asterisk-Token')
            ?? $request->bearerToken();

        return is_string($provided) && hash_equals($expected, $provided);
    }

    /**
     * Accept both the CRM's field names and Asterisk's native ones.
     */
    private function normalize(Request $request): array
    {
        $input = $request->all();

        $map = [
            'UNIQUEID'      => 'channel_id',
            'LINKEDID'      => 'linked_id',
            'CHANNEL'       => 'channel',
            'CALLERID'      => 'from_number',
            'EXTEN'         => 'to_number',
            'HANGUPCAUSE'   => 'hangup_cause',
            'BILLSEC'       => 'billsec',
            'DURATION'      => 'duration',
            'CRM_CALL_ID'   => 'crm_call_id',
            'CRM_TOKEN'     => 'token_var',
            'MIXMONITOR_FILENAME' => 'recording_path',
        ];

        foreach ($map as $from => $to) {
            if (isset($input[$from]) && ! isset($input[$to])) {
                $input[$to] = $input[$from];
            }
        }

        // The originate correlation token travels as its own field.
        $input['token'] = $input['token_var'] ?? $input['crm_token'] ?? null;

        return $input;
    }

    /**
     * Translate an ISDN hangup cause into a CRM call status.
     */
    private function statusFromCause(?string $cause, CallLog $call): ?string
    {
        if ($call->answered_at) {
            return 'completed';
        }

        return match ((int) $cause) {
            17      => 'busy',
            18, 19, 20 => 'no-answer',
            21      => 'rejected',
            27, 28  => 'failed',
            34, 38  => 'congestion',
            0       => null,
            default => 'no-answer',
        };
    }
}
