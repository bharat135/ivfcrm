<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Services\Telephony\CallService;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\Tata\TataBroadcastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Call events pushed by Tata Tele Business Services (Smartflo).
 *
 * Configure in the Smartflo portal under API Connect → Webhook, one webhook
 * per trigger, all pointing at:
 *
 *   https://<crm>/api/webhooks/tata/{event}?token=<Tata webhook token>
 *
 * where {event} is `answered`, `hangup` or `inbound` (or omit it and the
 * payload is inspected). Choose POST + application/json and include at least:
 *
 *   $call_id $uuid $ref_id $direction $call_status $call_to_number
 *   $caller_id_number $customer_number $answered_agent_number $agent_number
 *   $start_stamp $answer_stamp $end_stamp $duration $billsec $hangup_cause
 *   $recording_url $custom_identifier $did_number
 *
 * Smartflo retries a webhook that does not answer, so every handler is
 * idempotent and the response is always 200 once the token has been checked.
 */
class TataWebhookController extends Controller
{
    public function __construct(
        private CallService $calls,
        private SmartfloClient $tata,
        private TataBroadcastService $broadcasts,
    ) {}

    public function handle(Request $request, ?string $event = null): JsonResponse
    {
        if (! $this->authorized($request)) {
            Log::warning('Tata webhook rejected: bad token', ['ip' => $request->ip(), 'event' => $event]);

            return response()->json(['error' => 'unauthorized'], 403);
        }

        $data  = $this->normalize($request->all());
        $event = $event ?: $this->inferEvent($data);

        try {
            $result = match ($event) {
                'answered'                       => $this->answered($data),
                'hangup', 'missed', 'completed'  => $this->hangup($data),
                'inbound', 'ringing', 'received' => $this->ringing($data),
                default                          => $this->unknown($event, $data),
            };
        } catch (\Throwable $e) {
            Log::error('Tata webhook failed', ['event' => $event, 'error' => $e->getMessage()]);

            return response()->json(['received' => true, 'error' => $e->getMessage()]);
        }

        return response()->json(['received' => true, 'event' => $event] + $result);
    }

    // -----------------------------------------------------------------
    // Events
    // -----------------------------------------------------------------

    /**
     * Call reached the server / agent's phone is ringing. Opens a log row for
     * calls the CRM did not originate (inbound, or dialled from the handset).
     */
    private function ringing(array $data): array
    {
        $call = $this->findOrOpen($data);

        if (! $call) {
            return ['matched' => false];
        }

        if (! $call->answered_at && ! $call->ended_at) {
            $this->calls->markRinging($call, $data);
        }

        $this->calls->recordEvent($call, 'tata', 'ringing', $data['channel_id'] ?? $data['provider_call_id'] ?? null, $data);

        return ['call_id' => $call->id];
    }

    private function answered(array $data): array
    {
        $call = $this->findOrOpen($data);

        if (! $call) {
            return ['matched' => false];
        }

        if ($call->answered_at) {
            return ['call_id' => $call->id, 'duplicate' => true];
        }

        $this->calls->markAnswered($call, $data + ['timestamp' => $data['answer_stamp'] ?? null]);
        $this->calls->recordEvent($call, 'tata', 'answered', $data['channel_id'] ?? null, $data);

        return ['call_id' => $call->id];
    }

    private function hangup(array $data): array
    {
        $call = $this->findOrOpen($data);

        if (! $call) {
            return ['matched' => false];
        }

        if ($call->ended_at && $call->status !== 'ringing') {
            // A late recording URL is still worth attaching.
            if (! empty($data['recording_url']) && ! $call->recording_url) {
                $call->update(['recording_url' => $data['recording_url'], 'is_recorded' => true]);
            }

            return ['call_id' => $call->id, 'duplicate' => true];
        }

        $answered = $this->wasAnswered($data);

        // The hangup payload carries the answer time; a separate "answered"
        // webhook may not have been configured, so honour it here.
        if ($answered && ! $call->answered_at) {
            $this->calls->markAnswered($call, [
                'timestamp'  => $data['answer_stamp'] ?? $data['start_stamp'] ?? null,
                'channel_id' => $data['channel_id'] ?? null,
                'linked_id'  => $data['linked_id'] ?? null,
            ]);
            $call->refresh();
        }

        $this->calls->finalize($call, array_filter([
            'timestamp'        => $data['end_stamp'] ?? null,
            'talk_seconds'     => isset($data['billsec']) ? (int) $data['billsec'] : ($answered ? null : 0),
            'duration_seconds' => isset($data['duration']) ? (int) $data['duration'] : null,
            'hangup_cause'     => $data['hangup_cause'] ?? null,
            'hangup_by'        => $data['hangup_by'] ?? null,
            'status'           => $this->status($data, $answered),
            'recording_url'    => $data['recording_url'] ?? null,
        ], fn ($v) => $v !== null));

        $this->calls->recordEvent($call, 'tata', 'hangup', $data['channel_id'] ?? null, $data);

        // Voice-broadcast progress rides the same webhook.
        $this->broadcasts->applyEvent($data + ['call_log_id' => $call->id]);

        return ['call_id' => $call->id];
    }

    private function unknown(string $event, array $data): array
    {
        $call = $this->calls->locate($data);

        $this->calls->recordEvent($call, 'tata', $event, $data['channel_id'] ?? null, $data);

        return ['logged' => true, 'call_id' => $call?->id];
    }

    // -----------------------------------------------------------------
    // Correlation
    // -----------------------------------------------------------------

    /**
     * Find the CallLog this event belongs to, opening one for calls the CRM
     * did not place. Returns null only when the payload has no numbers at all.
     */
    private function findOrOpen(array $data): ?CallLog
    {
        if ($call = $this->calls->locate($data)) {
            // First time Smartflo tells us its own ids for a CRM-originated call.
            // Its start_stamp is also authoritative: the CRM row was opened when
            // the API request was made, a few seconds before the PBX started.
            $call->fill(array_filter([
                'channel_id' => $call->channel_id ?: ($data['channel_id'] ?? null),
                'linked_id'  => $call->linked_id ?: ($data['linked_id'] ?? null),
                'started_at' => ! $call->answered_at && ! empty($data['start_stamp']) ? $data['start_stamp'] : null,
            ]))->save();

            return $call;
        }

        $customer = $data['direction'] === 'inbound' ? ($data['from_number'] ?? null) : ($data['to_number'] ?? null);

        if (! $customer) {
            return null;
        }

        return $this->calls->recordExternal([
            'provider'         => 'tata',
            'direction'        => $data['direction'],
            'from_number'      => $data['from_number'] ?? null,
            'to_number'        => $data['to_number'] ?? null,
            'agent_number'     => $data['agent_number'] ?? null,
            'provider_call_id' => $data['provider_call_id'] ?? null,
            'channel_id'       => $data['channel_id'] ?? null,
            'linked_id'        => $data['linked_id'] ?? null,
            'started_at'       => $data['start_stamp'] ?? null,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function authorized(Request $request): bool
    {
        $expected = $this->tata->webhookToken();

        if (! $expected) {
            return false;
        }

        $provided = $request->query('token')
            ?? $request->input('token')
            ?? $request->header('X-Webhook-Token')
            ?? $request->header('X-Tata-Token')
            ?? $request->bearerToken();

        return is_string($provided) && hash_equals($expected, $provided);
    }

    /**
     * Map Smartflo's webhook variables onto the CRM's neutral field names.
     * Variables arrive with or without their `$` prefix depending on how the
     * webhook body was templated in the portal.
     */
    private function normalize(array $input): array
    {
        $in = [];
        foreach ($input as $key => $value) {
            $in[ltrim((string) $key, '$')] = $value;
        }

        $direction = strtolower((string) ($in['direction'] ?? ''));
        $direction = match (true) {
            str_contains($direction, 'inbound'), $direction === 'in' => 'inbound',
            $direction === ''                                        => isset($in['ref_id']) || isset($in['custom_identifier']) ? 'outbound' : 'inbound',
            default                                                  => 'outbound', // outbound, click_to_call, dialer, broadcast
        };

        $customer = $in['customer_number_with_prefix'] ?? $in['customer_number'] ?? null;
        $answeredAgent = $in['answered_agent'] ?? null;
        $answeredAgentNumber = $in['answered_agent_number']
            ?? (is_array($answeredAgent) ? ($answeredAgent['number'] ?? $answeredAgent['follow_me_number'] ?? null) : null);

        $agentNumber = $answeredAgentNumber ?: ($in['agent_number'] ?? null);

        if (! $agentNumber && ! empty($in['missed_agent'])) {
            $missed = is_array($in['missed_agent']) ? ($in['missed_agent'][0] ?? $in['missed_agent']) : null;
            $agentNumber = is_array($missed) ? ($missed['number'] ?? $missed['follow_me_number'] ?? null) : null;
        }

        // For click-to-call, Smartflo's "caller_id_number" is the DID shown to the
        // customer and "call_to_number" is the customer.
        $from = $direction === 'inbound'
            ? ($customer ?: $in['caller_id_number'] ?? $in['from'] ?? null)
            : ($in['caller_id_number'] ?? $in['did_number'] ?? $in['from'] ?? null);

        $to = $direction === 'inbound'
            ? ($in['call_to_number'] ?? $in['did_number'] ?? $in['to'] ?? null)
            : ($customer ?: $in['call_to_number'] ?? $in['to'] ?? null);

        $custom = $this->customIdentifier($in['custom_identifier'] ?? null);

        return array_filter([
            'direction'        => $direction,
            'channel_id'       => $this->str($in['call_id'] ?? null),
            'linked_id'        => $this->str($in['uuid'] ?? null),
            'provider_call_id' => $this->str($in['ref_id'] ?? null),
            'crm_call_id'      => $custom['crm_call_id'] ?? null,
            'from_number'      => $this->str($from),
            'to_number'        => $this->str($to),
            'agent_number'     => $this->str($agentNumber),
            'agent_name'       => $in['answered_agent_name'] ?? $in['agent_name'] ?? null,
            'call_status'      => isset($in['call_status']) ? strtolower((string) $in['call_status']) : null,
            'start_stamp'      => $this->stamp($in['start_stamp'] ?? $in['date'] ?? null),
            'answer_stamp'     => $this->stamp($in['answer_stamp'] ?? null),
            'end_stamp'        => $this->stamp($in['end_stamp'] ?? null),
            'duration'         => isset($in['duration']) ? (int) $in['duration'] : null,
            'billsec'          => isset($in['billsec']) ? (int) $in['billsec'] : (isset($in['answered_seconds']) ? (int) $in['answered_seconds'] : null),
            'hangup_cause'     => $this->str($in['hangup_cause'] ?? $in['reason_key'] ?? null),
            'recording_url'    => $this->str($in['recording_url'] ?? null),
            'did_number'       => $this->str($in['did_number'] ?? null),
            'raw'              => $in,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function inferEvent(array $data): string
    {
        if (isset($data['end_stamp']) || isset($data['call_status']) || isset($data['hangup_cause']) || isset($data['billsec'])) {
            return 'hangup';
        }

        if (isset($data['answer_stamp'])) {
            return 'answered';
        }

        return 'ringing';
    }

    private function wasAnswered(array $data): bool
    {
        $status = $data['call_status'] ?? null;

        if ($status !== null) {
            return in_array($status, ['answered', 'connected', 'completed', 'success'], true);
        }

        return ! empty($data['answer_stamp']) || (int) ($data['billsec'] ?? 0) > 0;
    }

    private function status(array $data, bool $answered): string
    {
        if ($answered) {
            return 'completed';
        }

        $cause = strtolower((string) ($data['hangup_cause'] ?? ''));

        return match (true) {
            str_contains($cause, 'busy')                                   => 'busy',
            str_contains($cause, 'reject'), str_contains($cause, 'decline') => 'rejected',
            str_contains($cause, 'cancel'), str_contains($cause, 'originator') => 'cancelled',
            str_contains($cause, 'congest'), str_contains($cause, 'circuit') => 'congestion',
            str_contains($cause, 'invalid'), str_contains($cause, 'unalloc'), str_contains($cause, 'fail') => 'failed',
            default                                                        => 'no-answer',
        };
    }

    /** custom_identifier comes back as an object, a JSON string, or "k=v" text. */
    private function customIdentifier(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/crm_call_id\D*(\d+)/', $raw, $m)) {
            return ['crm_call_id' => $m[1]];
        }

        return ctype_digit(trim($raw)) ? ['crm_call_id' => trim($raw)] : ['value' => $raw];
    }

    private function stamp(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            // Smartflo sends IST wall-clock time unless the webhook is set to UTC/epoch.
            $tz = config('telephony.tata.webhook_timezone', 'Asia/Kolkata');

            return (is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)->setTimezone(config('app.timezone'))
                : Carbon::parse((string) $value, $tz))->setTimezone(config('app.timezone'))->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function str(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
