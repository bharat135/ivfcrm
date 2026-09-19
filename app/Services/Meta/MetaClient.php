<?php

namespace App\Services\Meta;

use App\Support\SettingsRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for the Meta Graph API.
 *
 * Every Meta service in the CRM goes through here so that credential loading,
 * appsecret_proof signing, error translation and request logging live in one
 * place. Credentials come from Settings -> Integrations at call time, meaning
 * the user can rotate a token without a deploy or a config:cache clear.
 */
class MetaClient
{
    public function __construct(private SettingsRepository $settings) {}

    // -----------------------------------------------------------------
    // Credential accessors
    // -----------------------------------------------------------------

    public function credential(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get('meta', $key);

        if ($value === null || $value === '') {
            return config("meta.defaults.$key") ?? $default;
        }

        return $value;
    }

    public function accessToken(): ?string
    {
        return $this->credential('access_token');
    }

    public function adAccountId(): ?string
    {
        $id = $this->credential('ad_account_id');

        if (! $id) {
            return null;
        }

        // Accept either "act_123" or the bare numeric id.
        return str_starts_with($id, 'act_') ? $id : 'act_' . $id;
    }

    public function pageId(): ?string
    {
        return $this->credential('page_id');
    }

    public function wabaId(): ?string
    {
        return $this->credential('waba_id');
    }

    public function phoneNumberId(): ?string
    {
        return $this->credential('phone_number_id');
    }

    public function verifyToken(): ?string
    {
        return $this->credential('webhook_verify_token');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->accessToken());
    }

    public function isWhatsAppConfigured(): bool
    {
        return $this->isConfigured() && ! empty($this->phoneNumberId());
    }

    public function isAdsConfigured(): bool
    {
        return $this->isConfigured() && ! empty($this->adAccountId());
    }

    public function graphUrl(string $path = ''): string
    {
        $base = rtrim(config('meta.graph_url'), '/') . '/' . config('meta.graph_version');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }

    // -----------------------------------------------------------------
    // Verbs
    // -----------------------------------------------------------------

    public function get(string $path, array $query = [], ?string $token = null): array
    {
        return $this->send('get', $path, $query, $token);
    }

    public function post(string $path, array $payload = [], ?string $token = null): array
    {
        return $this->send('post', $path, $payload, $token);
    }

    public function delete(string $path, array $payload = [], ?string $token = null): array
    {
        return $this->send('delete', $path, $payload, $token);
    }

    /**
     * Walk a paginated edge, returning every node. `$maxPages` guards against
     * an unbounded crawl on a large ad account.
     */
    public function paginate(string $path, array $query = [], int $maxPages = 25, ?string $token = null): array
    {
        $out = [];
        $query['limit'] = $query['limit'] ?? 100;
        $page = 0;

        $response = $this->get($path, $query, $token);

        while (true) {
            $out = array_merge($out, $response['data'] ?? []);
            $page++;

            $next = $response['paging']['next'] ?? null;
            if (! $next || $page >= $maxPages) {
                break;
            }

            // `next` is an absolute, fully-signed URL — call it directly.
            $raw = $this->request($token)->get($next);
            $response = $this->handle($raw, 'GET', $next);
        }

        return $out;
    }

    /**
     * Upload a binary file to a Graph endpoint (WhatsApp media, ad images).
     */
    public function upload(string $path, string $absolutePath, string $fieldName, array $payload = [], ?string $token = null): array
    {
        $url = $this->graphUrl($path);

        $raw = $this->request($token)
            ->attach($fieldName, file_get_contents($absolutePath), basename($absolutePath))
            ->post($url, $this->withProof($payload, $token));

        return $this->handle($raw, 'POST(upload)', $url);
    }

    /**
     * Download binary content from a Meta CDN URL. Meta requires the bearer
     * token on media URLs, which is why this does not use a plain file_get.
     */
    public function download(string $url, ?string $token = null): ?string
    {
        $response = Http::withToken($token ?? $this->accessToken())
            ->timeout(config('meta.timeout'))
            ->get($url);

        if ($response->failed()) {
            Log::warning('Meta media download failed', [
                'url'    => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $response->body();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function send(string $method, string $path, array $data, ?string $token): array
    {
        $token = $token ?? $this->accessToken();

        if (! $token) {
            throw new MetaException('Meta access token is not configured. Add it under Settings → Integrations.');
        }

        $url = $this->graphUrl($path);
        $data = $this->withProof($data, $token);

        $raw = $this->request($token)->{$method}($url, $data);

        return $this->handle($raw, strtoupper($method), $url);
    }

    private function request(?string $token): PendingRequest
    {
        return Http::withToken($token ?? $this->accessToken())
            ->timeout(config('meta.timeout'))
            ->retry(config('meta.retries'), 500, function ($exception, $request) {
                // Only retry connection-level failures; Graph 4xx are not retried.
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
            ->acceptJson();
    }

    /**
     * appsecret_proof hardens calls against a stolen token. Meta only enforces
     * it when the app requires it, but sending it is always safe.
     */
    private function withProof(array $data, ?string $token): array
    {
        $secret = $this->credential('app_secret');
        $token  = $token ?? $this->accessToken();

        if ($secret && $token && ! isset($data['appsecret_proof'])) {
            $data['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        }

        return $data;
    }

    private function handle(Response $response, string $method, string $url): array
    {
        $body = $response->json() ?? [];

        if ($response->failed() || isset($body['error'])) {
            $exception = MetaException::fromResponse($body, $response->status());

            Log::error('Meta Graph API error', [
                'method'   => $method,
                'url'      => $this->scrub($url),
                'status'   => $response->status(),
                'code'     => $exception->errorCode,
                'subcode'  => $exception->errorSubcode,
                'message'  => $exception->getMessage(),
                'trace_id' => $exception->traceId,
            ]);

            throw $exception;
        }

        return $body;
    }

    /**
     * Never let a token reach the log file.
     */
    private function scrub(string $url): string
    {
        return preg_replace('/(access_token|appsecret_proof)=[^&]+/', '$1=REDACTED', $url);
    }
}
