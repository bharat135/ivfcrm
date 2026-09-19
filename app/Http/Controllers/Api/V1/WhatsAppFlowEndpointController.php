<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Meta\Flows\FlowDecryptionException;
use App\Services\Meta\Flows\FlowEncryption;
use App\Services\Meta\Flows\FlowEndpointException;
use App\Services\Meta\Flows\FlowEndpointHandler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The data-exchange endpoint for endpoint-backed WhatsApp Flows.
 *
 * Register this URL as the Flow's `endpoint_uri`:
 *     https://your-domain/api/webhooks/whatsapp-flow
 *
 * Every request and response is encrypted (see FlowEncryption). Meta requires:
 *   - HTTPS with a publicly trusted certificate
 *   - a plain base64 string as the body of a 200 response, not JSON
 *   - 421 when the key does not match, so the client re-fetches the public key
 *
 * The endpoint must also answer a `ping` health check before Meta will allow
 * the flow to be published.
 */
class WhatsAppFlowEndpointController extends Controller
{
    public function __construct(
        private FlowEncryption $encryption,
        private FlowEndpointHandler $handler,
    ) {}

    public function __invoke(Request $request): Response
    {
        // 1. Decrypt ------------------------------------------------------
        try {
            $decrypted = $this->encryption->decryptRequest($request->all());
        } catch (FlowDecryptionException $e) {
            Log::warning('WhatsApp flow endpoint could not decrypt a request', [
                'error'        => $e->getMessage(),
                'key_mismatch' => $e->keyMismatch,
                'ip'           => $request->ip(),
            ]);

            // A bare status; the body cannot be encrypted without the key.
            return response('', $e->httpStatus());
        } catch (\Throwable $e) {
            Log::error('WhatsApp flow endpoint decryption failed', ['error' => $e->getMessage()]);

            return response('', 432);
        }

        $payload = $decrypted['payload'];

        // 2. Handle -------------------------------------------------------
        try {
            $reply = $this->handler->handle($payload);
        } catch (FlowEndpointException $e) {
            Log::warning('WhatsApp flow endpoint rejected a request', [
                'error'  => $e->getMessage(),
                'action' => $payload['action'] ?? null,
                'screen' => $payload['screen'] ?? null,
            ]);

            // Encrypted so the client can render it rather than hanging.
            $reply = ['data' => ['error_message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            Log::error('WhatsApp flow endpoint failed', [
                'error'  => $e->getMessage(),
                'action' => $payload['action'] ?? null,
            ]);

            $reply = ['data' => ['error_message' => 'Something went wrong. Our team will follow up with you.']];
        }

        // 3. Encrypt ------------------------------------------------------
        try {
            $body = $this->encryption->encryptResponse($reply, $decrypted['aesKey'], $decrypted['iv']);
        } catch (\Throwable $e) {
            Log::error('WhatsApp flow endpoint could not encrypt its response', ['error' => $e->getMessage()]);

            return response('', 500);
        }

        // Meta expects the raw base64 string, not a JSON envelope.
        return response($body, 200)->header('Content-Type', 'text/plain');
    }
}
