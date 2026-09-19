<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\WhatsappCallPermission;
use Illuminate\Support\Carbon;

/**
 * WhatsApp Business Calling API (Cloud API).
 *
 * Two call directions exist and they behave differently:
 *
 *  - USER_INITIATED: the customer taps Call on the business profile. Meta sends
 *    a `connect` webhook containing an SDP *offer*; the business replies with
 *    pre_accept/accept and its own SDP *answer*.
 *  - BUSINESS_INITIATED: the business calls the customer. This requires an
 *    approved, unexpired call permission, which is requested in-chat.
 *
 * Media is WebRTC. This class handles signalling and permissions only — the SDP
 * it sends must come from a real WebRTC endpoint (see the `bridge` config and
 * WhatsAppCallBridge).
 */
class WhatsAppCallingService
{
    public function __construct(private MetaClient $client) {}

    private function phoneNumberId(?ChannelAccount $account = null): string
    {
        $id = $account?->credentials['phone_number_id'] ?? null;
        $id = $id ?: $this->client->phoneNumberId();

        if (! $id) {
            throw new MetaException('WhatsApp phone number ID is not configured.');
        }

        return $id;
    }

    private function token(?ChannelAccount $account = null): ?string
    {
        return $account?->credentials['access_token'] ?? null;
    }

    public function isEnabled(): bool
    {
        return (bool) config('telephony.whatsapp_calling.enabled')
            && $this->client->isWhatsAppConfigured();
    }

    // -----------------------------------------------------------------
    // Account-level calling settings
    // -----------------------------------------------------------------

    /**
     * Turn calling on for the business number and set when the call button is
     * visible. Without this, neither direction works.
     */
    public function enableCalling(array $options = [], ?ChannelAccount $account = null): array
    {
        $calling = [
            'status'                     => $options['status'] ?? 'ENABLED',
            'call_icon_visibility'       => $options['call_icon_visibility'] ?? 'DEFAULT',
            'callback_permission_status' => $options['callback_permission_status'] ?? 'ENABLED',
        ];

        if (! empty($options['call_hours'])) {
            $calling['call_hours'] = $options['call_hours'];
        }

        if (! empty($options['sip'])) {
            $calling['sip'] = $options['sip'];
        }

        return $this->client->post(
            $this->phoneNumberId($account) . '/settings',
            ['calling' => json_encode($calling)],
            $this->token($account),
        );
    }

    public function callingSettings(?ChannelAccount $account = null): array
    {
        $response = $this->client->get(
            $this->phoneNumberId($account) . '/settings',
            ['include_sip_credentials' => 'false'],
            $this->token($account),
        );

        return $response['calling'] ?? [];
    }

    /**
     * Point WhatsApp calling at the clinic's own Asterisk over SIP.
     *
     * This is the mode to prefer: Meta terminates the call on your SIP server
     * directly, so calls ring the agent's MicroSIP extension through the normal
     * dialplan and there is no WebRTC/SDP handling in PHP at all. The
     * alternative (connect/pre_accept/accept with raw SDP) is only needed when
     * no SIP server is available.
     *
     * @param  string  $hostname  Publicly reachable Asterisk SIP host (TLS).
     */
    public function configureSipServer(
        string $hostname,
        int $port = 5061,
        array $userParams = [],
        ?ChannelAccount $account = null,
    ): array {
        return $this->enableCalling([
            'sip' => [
                'status'  => 'ENABLED',
                'servers' => [array_filter([
                    'hostname'                 => $hostname,
                    'port'                     => $port,
                    'request_uri_user_params'  => $userParams ?: null,
                ], fn ($v) => $v !== null)],
            ],
        ], $account);
    }

    public function disableSipServer(?ChannelAccount $account = null): array
    {
        return $this->enableCalling(['sip' => ['status' => 'DISABLED']], $account);
    }

    /**
     * Whether Meta is currently routing calls to our own SIP server, which
     * decides whether the CRM must handle SDP itself.
     */
    public function usesSipRouting(?ChannelAccount $account = null): bool
    {
        try {
            $settings = $this->callingSettings($account);
        } catch (\Throwable $e) {
            // Unknown means "assume not routed"; never let a lookup break a call.
            return false;
        }

        return strtoupper((string) ($settings['sip']['status'] ?? 'DISABLED')) === 'ENABLED';
    }

    /**
     * Standard weekly call-hours block, Monday–Saturday clinic hours.
     */
    public function defaultCallHours(string $open = '09:00', string $close = '19:00'): array
    {
        $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'];

        return [
            'status'          => 'ENABLED',
            'timezone_id'     => config('telephony.whatsapp_calling.call_hours.timezone'),
            'weekly_operating_hours' => array_map(fn ($day) => [
                'day_of_week' => $day,
                'open_time'   => str_replace(':', '', $open),
                'close_time'  => str_replace(':', '', $close),
            ], $days),
        ];
    }

    // -----------------------------------------------------------------
    // Call permissions
    // -----------------------------------------------------------------

    /**
     * Ask the customer, inside the chat, for permission to call them.
     * Requires an open 24h session window — it is an interactive message.
     */
    public function requestPermission(string $to, ?ChannelAccount $account = null): array
    {
        return $this->client->post(
            $this->phoneNumberId($account) . '/messages',
            [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $this->digits($to),
                'type'              => 'interactive',
                'interactive'       => [
                    'type'   => 'call_permission_request',
                    'action' => ['name' => 'call_permission_request'],
                ],
            ],
            $this->token($account),
        );
    }

    /**
     * Meta's view of whether this business may currently call a user.
     */
    public function permissionState(string $waId, ?ChannelAccount $account = null): array
    {
        $response = $this->client->get(
            $this->phoneNumberId($account) . '/call_permissions',
            ['user_wa_id' => $this->digits($waId)],
            $this->token($account),
        );

        return $response['permission'] ?? $response['data'][0] ?? $response;
    }

    /**
     * Reconcile Meta's permission state into the local mirror.
     */
    public function syncPermission(string $waId, ?Conversation $conversation = null, ?ChannelAccount $account = null): WhatsappCallPermission
    {
        $remote = [];

        try {
            $remote = $this->permissionState($waId, $account);
        } catch (\Throwable $e) {
            // "No permission on record" is a normal 4xx here. A transport
            // failure is also survivable: fall through to the local mirror
            // rather than break the dialer because Meta is unreachable.
        }

        $status = strtolower((string) ($remote['status'] ?? 'none'));

        $mapped = match ($status) {
            'approved', 'granted'  => 'approved',
            'pending', 'requested' => 'pending',
            'rejected', 'denied'   => 'rejected',
            'revoked'              => 'revoked',
            'expired'              => 'expired',
            default                => 'pending',
        };

        $expires = isset($remote['expiration_timestamp'])
            ? Carbon::createFromTimestamp((int) $remote['expiration_timestamp'])->setTimezone(config('app.timezone'))
            : null;

        return WhatsappCallPermission::updateOrCreate(
            ['wa_id' => $this->digits($waId)],
            [
                'conversation_id' => $conversation?->id,
                'lead_id'         => $conversation?->lead_id,
                'patient_id'      => $conversation?->patient_id,
                'status'          => $mapped,
                'expires_at'      => $expires,
                'calls_remaining' => isset($remote['calls_remaining']) ? (int) $remote['calls_remaining'] : null,
                'responded_at'    => in_array($mapped, ['approved', 'rejected', 'revoked'], true) ? now() : null,
                'meta'            => $remote ?: null,
            ],
        );
    }

    // -----------------------------------------------------------------
    // Call lifecycle
    // -----------------------------------------------------------------

    /**
     * Business-initiated call. `$sdpOffer` must come from a WebRTC endpoint
     * that will carry the audio.
     */
    public function connect(string $to, string $sdpOffer, ?ChannelAccount $account = null): array
    {
        return $this->client->post(
            $this->phoneNumberId($account) . '/calls',
            [
                'messaging_product' => 'whatsapp',
                'to'                => $this->digits($to),
                'action'            => 'connect',
                'session'           => json_encode(['sdp_type' => 'offer', 'sdp' => $sdpOffer]),
            ],
            $this->token($account),
        );
    }

    /**
     * Signal "ringing" on an incoming call while the agent is being found.
     * Sending the SDP answer early lets Meta start media setup, which
     * noticeably shortens the silence the caller hears.
     */
    public function preAccept(string $callId, string $sdpAnswer, ?ChannelAccount $account = null): array
    {
        return $this->client->post(
            $this->phoneNumberId($account) . '/calls',
            [
                'messaging_product' => 'whatsapp',
                'call_id'           => $callId,
                'action'            => 'pre_accept',
                'session'           => json_encode(['sdp_type' => 'answer', 'sdp' => $sdpAnswer]),
            ],
            $this->token($account),
        );
    }

    public function accept(string $callId, string $sdpAnswer, ?ChannelAccount $account = null): array
    {
        return $this->client->post(
            $this->phoneNumberId($account) . '/calls',
            [
                'messaging_product' => 'whatsapp',
                'call_id'           => $callId,
                'action'            => 'accept',
                'session'           => json_encode(['sdp_type' => 'answer', 'sdp' => $sdpAnswer]),
            ],
            $this->token($account),
        );
    }

    public function reject(string $callId, ?ChannelAccount $account = null): array
    {
        return $this->client->post(
            $this->phoneNumberId($account) . '/calls',
            [
                'messaging_product' => 'whatsapp',
                'call_id'           => $callId,
                'action'            => 'reject',
            ],
            $this->token($account),
        );
    }

    public function terminate(string $callId, ?ChannelAccount $account = null): array
    {
        return $this->client->post(
            $this->phoneNumberId($account) . '/calls',
            [
                'messaging_product' => 'whatsapp',
                'call_id'           => $callId,
                'action'            => 'terminate',
            ],
            $this->token($account),
        );
    }

    private function digits(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);

        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }

        return $digits;
    }
}
