<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Single webhook endpoint for the whole Meta product family: WhatsApp messages
 * and statuses, Lead Ads, and template review results.
 *
 * Configure in the Meta App dashboard as:
 *   Callback URL:  https://your-domain/api/webhooks/meta
 *   Verify token:  the value saved under Settings → Integrations
 *
 * Meta expects a 200 within a few seconds and retries the entire batch
 * otherwise, so this returns immediately and defers slow work to queues.
 */
class MetaWebhookController extends Controller
{
    public function __construct(
        private MetaWebhookProcessor $processor,
        private MetaClient $client,
    ) {}

    /**
     * GET — Meta's subscription handshake.
     */
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = $this->client->verifyToken();

        if ($mode === 'subscribe' && $expected && hash_equals($expected, (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('Meta webhook verification rejected', [
            'mode' => $mode,
            'ip'   => $request->ip(),
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Each Meta app signs with its own secret. Besides the app under
     * Settings → Integrations, a channel account may belong to a different
     * app (another developer account) and carry its own secret, so the body
     * is accepted when it matches any configured one.
     */
    private function signatureValid(?string $header, string $raw): bool
    {
        $secrets = ChannelAccount::where('is_active', true)
            ->get()
            ->map(fn (ChannelAccount $account) => $account->credentials['app_secret'] ?? null)
            ->toBase()
            ->prepend($this->client->credential('app_secret'))
            ->filter()
            ->unique()
            ->values();

        if ($secrets->isEmpty()) {
            // Nothing configured yet — allow, the setup screen warns about it.
            return MetaWebhookProcessor::verifySignature($header, $raw, null);
        }

        return $secrets->contains(fn (string $secret) => MetaWebhookProcessor::verifySignature($header, $raw, $secret));
    }

    /**
     * POST — the event batch.
     */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->signatureValid($request->header('X-Hub-Signature-256'), $raw)) {
            Log::warning('Meta webhook signature mismatch', ['ip' => $request->ip()]);

            // 403 tells Meta the delivery was rejected; do not process the body.
            return response()->json(['status' => 'invalid signature'], 403);
        }

        $payload = $request->all();
        $event   = $this->processor->log('meta', $payload);

        try {
            $this->processor->handle($payload);

            $event->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Throwable $e) {
            $event->update([
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);

            Log::error('Meta webhook processing failed', [
                'event_id' => $event->id,
                'error'    => $e->getMessage(),
            ]);
        }

        // Always acknowledge: a non-200 makes Meta replay the whole batch,
        // and the failure is already recorded in webhook_events for replay.
        return response()->json(['status' => 'ok']);
    }
}
