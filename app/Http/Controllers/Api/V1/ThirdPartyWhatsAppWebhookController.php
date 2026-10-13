<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Services\Meta\MetaWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound events from the third-party WhatsApp API (1automations).
 *
 * The provider relays Meta's Cloud API events, so the payload is normalised to
 * Meta's `whatsapp_business_account` envelope and handed to the same processor
 * the Meta webhook uses — conversations, statuses, media and lead linking all
 * behave identically. Every raw payload is kept in webhook_events (source
 * "1automations") so a format change on the provider side is visible.
 *
 * The provider documents no request signature, so the URL carries a random
 * per-account token generated in Settings → Integrations.
 */
class ThirdPartyWhatsAppWebhookController extends Controller
{
    public function __construct(private MetaWebhookProcessor $processor) {}

    /**
     * Some providers ping the URL (GET) when a webhook is saved, and some use
     * Meta's hub.challenge handshake. Answer both for a valid token.
     */
    public function verify(Request $request, string $token)
    {
        if (! $this->accountFor($token)) {
            return response('Forbidden', 403);
        }

        // Wapzy/1automations verifies with ?echo=true&challange=XXXX (sic)
        // and expects the value echoed back; Meta-style uses hub.challenge.
        $challenge = $request->query('challange')
            ?? $request->query('challenge')
            ?? $request->query('hub_challenge');

        return $challenge !== null
            ? response((string) $challenge, 200)->header('Content-Type', 'text/plain')
            : response()->json(['status' => 'ok']);
    }

    public function handle(Request $request, string $token): JsonResponse
    {
        $account = $this->accountFor($token);

        if (! $account) {
            Log::warning('Third-party WhatsApp webhook with an unknown token', ['ip' => $request->ip()]);

            return response()->json(['status' => 'unknown webhook'], 403);
        }

        $raw   = $request->all();
        $event = $this->processor->log(ChannelAccount::PROVIDER_ONEAUTOMATIONS, $raw, $this->describe($raw));

        $payload = $this->normalise($raw, $account);

        if (! $payload) {
            $event->update(['status' => 'ignored', 'processed_at' => now(), 'error' => 'Payload is not in a recognised WhatsApp format.']);

            return response()->json(['status' => 'ignored']);
        }

        try {
            $this->processor->handle($payload, $account);
            $event->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            Log::error('Third-party WhatsApp webhook failed', ['event_id' => $event->id, 'error' => $e->getMessage()]);
        }

        // Always 200 for a valid token so the provider does not retry forever.
        return response()->json(['status' => 'ok']);
    }

    private function accountFor(string $token): ?ChannelAccount
    {
        if (strlen($token) < 16) {
            return null;
        }

        return ChannelAccount::where('channel', 'whatsapp')
            ->where('provider', ChannelAccount::PROVIDER_ONEAUTOMATIONS)
            ->get()
            ->first(fn (ChannelAccount $a) => ! empty($a->credentials['webhook_token'])
                && hash_equals((string) $a->credentials['webhook_token'], $token));
    }

    /**
     * Bring the provider's body into Meta's envelope:
     *   { object: whatsapp_business_account, entry: [ { changes: [ { field, value } ] } ] }
     *
     * Accepted shapes: Meta's envelope as is; an envelope without `object`;
     * one wrapped in `data` / `payload` / `body`; or a bare change `value`
     * (has messages / statuses).
     */
    public function normalise(array $raw, ChannelAccount $account): ?array
    {
        foreach (['data', 'payload', 'body'] as $wrapper) {
            if (isset($raw[$wrapper]) && is_array($raw[$wrapper]) && ! isset($raw['entry'])) {
                $inner = $this->normalise($raw[$wrapper], $account);

                if ($inner) {
                    return $inner;
                }
            }
        }

        if (isset($raw['entry']) && is_array($raw['entry'])) {
            $raw['object'] = $raw['object'] ?? 'whatsapp_business_account';

            return $raw;
        }

        // A single change: { field, value }.
        if (isset($raw['field'], $raw['value']) && is_array($raw['value'])) {
            return $this->envelope($raw['field'], $raw['value'], $account);
        }

        // A bare change value.
        if (isset($raw['messages']) || isset($raw['statuses'])) {
            return $this->envelope('messages', $raw, $account);
        }

        return null;
    }

    private function envelope(string $field, array $value, ChannelAccount $account): array
    {
        $value['messaging_product'] = $value['messaging_product'] ?? 'whatsapp';
        $value['metadata'] = ($value['metadata'] ?? []) + [
            'phone_number_id' => $account->credentials['phone_number_id'] ?? null,
        ];

        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => $account->credentials['waba_id'] ?? null,
                'changes' => [['field' => $field, 'value' => $value]],
            ]],
        ];
    }

    private function describe(array $raw): ?string
    {
        $type = $raw['event'] ?? $raw['type'] ?? $raw['event_type'] ?? null;

        if (is_string($type)) {
            return mb_substr($type, 0, 80);
        }

        $change = $raw['entry'][0]['changes'][0] ?? null;

        if ($change) {
            $value = $change['value'] ?? [];

            if (! empty($value['statuses'])) {
                return 'messages.status.' . ($value['statuses'][0]['status'] ?? 'unknown');
            }

            return ($change['field'] ?? 'unknown') . (! empty($value['messages']) ? '.' . ($value['messages'][0]['type'] ?? 'unknown') : '');
        }

        return null;
    }
}
