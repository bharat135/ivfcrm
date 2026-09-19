<?php

namespace App\Services\Telephony;

use App\Models\CallLog;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\WhatsappCallPermission;
use App\Services\Meta\MetaException;
use App\Services\Meta\WhatsAppCallingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turns WhatsApp calling webhooks into CallLog rows and permission records.
 *
 * A WhatsApp call is logged exactly like a PSTN call (`channel = whatsapp`), so
 * telecaller productivity and lead timelines cover both without special cases.
 *
 * Media routing is out of scope here: when Meta is configured to route calls to
 * the clinic's own SIP server (see WhatsAppCallingService::configureSipServer)
 * the audio reaches Asterisk directly and rings the agent's MicroSIP extension
 * through the normal dialplan. This class handles the signalling record.
 */
class WhatsAppCallHandler
{
    public function __construct(
        private CallService $calls,
        private WhatsAppCallingService $calling,
    ) {}

    /**
     * A `connect` event: either the customer is calling in, or Meta is
     * confirming a business-initiated call has been picked up.
     */
    public function handleIncomingWhatsAppCall(array $payload, ?Conversation $conversation, ?ChannelAccount $account = null): ?CallLog
    {
        $callId = $payload['id'] ?? null;

        if (! $callId) {
            return null;
        }

        // Meta retries webhook batches; never open two logs for one call.
        $existing = CallLog::where('wa_call_id', $callId)->first();

        if ($existing) {
            $this->calls->recordEvent($existing, 'whatsapp', 'connect', $callId, $payload);

            return $this->calls->markAnswered($existing, [
                'timestamp' => $this->timestamp($payload),
            ]);
        }

        $direction = strtoupper((string) ($payload['direction'] ?? 'USER_INITIATED'));
        $isInbound = $direction === 'USER_INITIATED';

        $from = (string) ($payload['from'] ?? '');
        $to   = (string) ($payload['to'] ?? '');

        $lead    = $conversation?->lead;
        $patient = $conversation?->patient;

        if (! $lead && ! $patient) {
            ['lead' => $lead, 'patient' => $patient] = $this->calls->resolveContact($isInbound ? $from : $to);
        }

        // Route to whoever owns the lead, otherwise leave unassigned for pickup.
        $agent = $lead?->assignedTo ?: $conversation?->assignee;

        $call = CallLog::create([
            'lead_id'      => $lead?->id,
            'patient_id'   => $patient?->id,
            'user_id'      => $agent?->id,
            'center_id'    => $agent?->center_id ?? $lead?->center_id,
            'provider'     => 'meta',
            'channel'      => 'whatsapp',
            'direction'    => $isInbound ? 'inbound' : 'outbound',
            'from_number'  => $from,
            'to_number'    => $to,
            'wa_call_id'   => $callId,
            'wa_direction' => $direction,
            'status'       => 'ringing',
            'started_at'   => $this->timestamp($payload),
            'meta'         => [
                'session'         => $payload['session'] ?? null,
                'conversation_id' => $conversation?->id,
            ],
        ]);

        $this->calls->recordEvent($call, 'whatsapp', 'connect', $callId, $payload);

        if ($agent) {
            $this->calls->setAgentStatus($agent, 'ringing', $call);
        }

        Log::info('WhatsApp call connected', [
            'call_log_id' => $call->id,
            'wa_call_id'  => $callId,
            'direction'   => $direction,
        ]);

        return $call;
    }

    /**
     * A `terminate` event closes the call and carries Meta's own duration.
     */
    public function handleWhatsAppTerminate(array $payload): ?CallLog
    {
        $callId = $payload['id'] ?? null;

        if (! $callId) {
            return null;
        }

        $call = CallLog::where('wa_call_id', $callId)->first();

        if (! $call) {
            return null;
        }

        $this->calls->recordEvent($call, 'whatsapp', 'terminate', $callId, $payload);

        $duration = isset($payload['duration']) ? (int) $payload['duration'] : null;
        $status   = strtolower((string) ($payload['status'] ?? ''));

        return $this->calls->finalize($call, array_filter([
            'timestamp'        => $this->timestamp($payload),
            'talk_seconds'     => $duration,
            'duration_seconds' => $duration,
            'status'           => match ($status) {
                'completed', 'success' => 'completed',
                'failed'               => 'failed',
                'rejected', 'declined' => 'no-answer',
                default                => $duration > 0 ? 'completed' : 'no-answer',
            },
            'hangup_cause' => $payload['termination_reason'] ?? $payload['status'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Any other call event (ringing, status updates) — logged for the timeline.
     */
    public function recordWhatsAppEvent(array $payload, string $event): void
    {
        $callId = $payload['id'] ?? null;

        $call = $callId ? CallLog::where('wa_call_id', $callId)->first() : null;

        $this->calls->recordEvent($call, 'whatsapp', $event, $callId, $payload);

        if ($call && $event === 'ringing') {
            $call->update(['status' => 'ringing']);
        }
    }

    /**
     * The customer accepted or declined a call-permission request.
     */
    public function updateWhatsAppPermission(array $payload): void
    {
        $waId = $payload['user_wa_id'] ?? $payload['wa_id'] ?? null;

        if (! $waId) {
            return;
        }

        $response = strtolower((string) ($payload['response'] ?? $payload['status'] ?? ''));

        $status = match ($response) {
            'accept', 'accepted', 'approved', 'granted' => 'approved',
            'reject', 'rejected', 'declined', 'denied'  => 'rejected',
            'revoked' => 'revoked',
            'expired' => 'expired',
            default   => 'pending',
        };

        $expires = isset($payload['expiration_timestamp'])
            ? Carbon::createFromTimestamp((int) $payload['expiration_timestamp'])->setTimezone(config('app.timezone'))
            : now()->addDays((int) config('telephony.whatsapp_calling.permission_ttl_days'));

        WhatsappCallPermission::updateOrCreate(
            ['wa_id' => preg_replace('/\D/', '', (string) $waId)],
            [
                'status'          => $status,
                'responded_at'    => now(),
                'expires_at'      => $status === 'approved' ? $expires : null,
                'calls_remaining' => isset($payload['calls_remaining']) ? (int) $payload['calls_remaining'] : null,
                'meta'            => $payload,
            ],
        );

        Log::info('WhatsApp call permission updated', ['wa_id' => $waId, 'status' => $status]);
    }

    // -----------------------------------------------------------------
    // Outbound helpers used by the dialer
    // -----------------------------------------------------------------

    /**
     * Whether the business may call this contact on WhatsApp right now.
     *
     * @return array{allowed:bool, reason:?string, permission:?WhatsappCallPermission}
     */
    public function permissionFor(string $waId, bool $refresh = false, ?ChannelAccount $account = null): array
    {
        $digits = preg_replace('/\D/', '', $waId);

        $permission = WhatsappCallPermission::where('wa_id', $digits)->latest('id')->first();

        // Local state can lag Meta's; refresh on demand from the dialer.
        if ($refresh || ! $permission) {
            try {
                $permission = $this->calling->syncPermission($digits, null, $account);
            } catch (\Throwable $e) {
                // A failed lookup must read as "no permission", not as a 500 —
                // the agent still needs the dialer when Meta is unreachable.
                Log::debug('Call permission lookup failed', ['error' => $e->getMessage()]);
            }
        }

        if (! $permission) {
            return [
                'allowed'    => false,
                'reason'     => 'No call permission from this contact yet.',
                'permission' => null,
            ];
        }

        return [
            'allowed'    => $permission->isUsable(),
            'reason'     => $permission->blockReason(),
            'permission' => $permission,
        ];
    }

    /**
     * Log a business-initiated WhatsApp call the agent placed from the CRM.
     */
    public function logOutboundAttempt(
        string $to,
        ?int $userId,
        ?Conversation $conversation,
        ?string $callId = null,
        array $payload = [],
    ): CallLog {
        ['lead' => $lead, 'patient' => $patient] = $this->calls->resolveContact($to);

        $call = CallLog::create([
            'lead_id'      => $conversation?->lead_id ?? $lead?->id,
            'patient_id'   => $conversation?->patient_id ?? $patient?->id,
            'user_id'      => $userId,
            'provider'     => 'meta',
            'channel'      => 'whatsapp',
            'direction'    => 'outbound',
            'to_number'    => preg_replace('/\D/', '', $to),
            'wa_call_id'   => $callId,
            'wa_direction' => 'BUSINESS_INITIATED',
            'status'       => $callId ? 'ringing' : 'failed',
            'started_at'   => now(),
            'meta'         => ['response' => $payload, 'conversation_id' => $conversation?->id],
        ]);

        $this->calls->recordEvent($call, 'whatsapp', 'originate', $callId, $payload);

        return $call;
    }

    private function timestamp(array $payload): Carbon
    {
        $value = $payload['timestamp'] ?? null;

        if (! $value) {
            return now();
        }

        return is_numeric($value)
            ? Carbon::createFromTimestamp((int) $value)->setTimezone(config('app.timezone'))
            : Carbon::parse($value);
    }
}
