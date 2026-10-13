<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
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
    /**
     * Per-call credential overrides from a ChannelAccount (see forAccount()).
     * Empty on the shared singleton; set only on the clone it hands out.
     *
     * @var array<string,mixed>
     */
    private array $overrides = [];

    public function __construct(private SettingsRepository $settings) {}

    /**
     * A client bound to a channel account's own credentials.
     *
     * A WhatsApp number that lives under another Meta app (a different
     * developer account) carries its own token *and* app id/secret. Every
     * credential lookup — token, appsecret_proof, phone number id, WABA —
     * falls through to the account first, then to Settings → Integrations,
     * so one override field is enough to change just that one thing.
     */
    public function forAccount(?ChannelAccount $account): static
    {
        $credentials = array_filter(
            $account?->credentials ?? [],
            fn ($value) => $value !== null && $value !== '',
        );

        if ($credentials === []) {
            return $this;
        }

        // A third-party provider (1automations) mirrors the Graph API under
        // its own base URL with its own access key. Remember that on the
        // bound client so URLs and signing follow the provider, not Meta.
        if ($account?->isThirdPartyWhatsApp()) {
            $credentials['__provider'] = $account->provider;
        }

        $bound = clone $this;
        $bound->overrides = $credentials;

        return $bound;
    }

    /**
     * True when this client talks to a third-party WhatsApp API that mirrors
     * Meta's endpoints rather than to graph.facebook.com directly.
     */
    public function isThirdParty(): bool
    {
        return ($this->overrides['__provider'] ?? null) === ChannelAccount::PROVIDER_ONEAUTOMATIONS;
    }

    private function providerBase(): string
    {
        $base = ChannelAccount::normaliseProviderUrl((string) ($this->overrides['app_url'] ?? ''));

        if ($base === '') {
            throw new MetaException('The third-party WhatsApp API "App URL" is not configured.');
        }

        return $base;
    }

    private function providerVersion(): string
    {
        return trim((string) ($this->overrides['api_version'] ?? '')) ?: config('meta.graph_version');
    }

    /**
     * Media upload/download URL. The provider's docs list
     * {app_url}/{version}/… for media, but that path 404s on the live
     * provider; upload and download both work under /api/meta like every
     * other call (verified 2026-10-11), so media uses the Graph URL.
     */
    public function mediaUrl(string $path = ''): string
    {
        return $this->graphUrl($path);
    }

    // -----------------------------------------------------------------
    // Credential accessors
    // -----------------------------------------------------------------

    public function credential(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }

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

    /**
     * The app access token ("id|secret"). It authenticates app-level edges
     * such as /{app-id}/subscriptions that a user or Page token cannot read.
     */
    public function appToken(): ?string
    {
        $id     = $this->credential('app_id');
        $secret = $this->credential('app_secret');

        return $id && $secret ? $id . '|' . $secret : null;
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
        $base = $this->isThirdParty()
            ? $this->providerBase() . '/api/meta/' . $this->providerVersion()
            : rtrim(config('meta.graph_url'), '/') . '/' . config('meta.graph_version');

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
     * What Meta says about a token: type, scopes, issued_at, expires_at,
     * is_valid. This is the only way to tell whether a saved token actually
     * carries a permission that was later added to the app.
     */
    public function debugToken(?string $token = null): array
    {
        $token = $token ?? $this->accessToken();

        return $this->get('debug_token', ['input_token' => $token], $token)['data'] ?? [];
    }

    /**
     * Webhook subscriptions configured on the app itself (object, callback
     * URL, fields, active). Independent of any WABA/Page-level subscription,
     * so it still answers when Meta's per-object read-back is failing.
     */
    public function appSubscriptions(): array
    {
        $appId = $this->credential('app_id');
        $token = $this->appToken();

        if (! $appId || ! $token) {
            throw new MetaException('App ID and App Secret are required to read the app\'s webhook configuration.');
        }

        return $this->get($appId . '/subscriptions', [], $token)['data'] ?? [];
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

            // Never hand a provider key to a URL on another host.
            if ($this->isThirdParty() && ! str_starts_with($next, $this->providerBase())) {
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
        $url = $this->mediaUrl($path);

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

    /**
     * Fetch inbound media through the provider: GET {app_url}/{version}/{media_id}.
     * The provider may answer with the file itself or with Meta-style JSON
     * carrying a `url`; both are handled.
     *
     * @return array{0:?string, 1:?string}  [binary, mime]
     */
    public function providerMedia(string $mediaId): array
    {
        $token    = $this->accessToken();
        $response = Http::withToken($token)->timeout(config('meta.timeout'))->get($this->mediaUrl($mediaId));

        if ($response->failed()) {
            Log::warning('Third-party WhatsApp media download failed', ['media_id' => $mediaId, 'status' => $response->status()]);

            return [null, null];
        }

        $type = strtolower((string) $response->header('Content-Type'));

        if (str_contains($type, 'json')) {
            $json = $response->json() ?? [];
            $url  = $json['url'] ?? $json['data']['url'] ?? null;

            return $url
                ? [$this->download($url, $token), $json['mime_type'] ?? $json['data']['mime_type'] ?? null]
                : [null, null];
        }

        return [$response->body(), trim(explode(';', $type)[0]) ?: null];
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
        // The provider authenticates with its own key; a proof signed with
        // the Meta app secret means nothing to it.
        if ($this->isThirdParty()) {
            return $data;
        }

        $secret = $this->credential('app_secret');
        $token  = $token ?? $this->accessToken();

        // An app access token ("id|secret") already proves the secret; Graph
        // rejects a proof computed over it.
        if ($secret && $token && ! str_contains($token, '|') && ! isset($data['appsecret_proof'])) {
            $data['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        }

        return $data;
    }

    private function handle(Response $response, string $method, string $url): array
    {
        $body = $response->json() ?? [];

        // The third-party API answers HTTP 200 with {"success":false,…} on errors.
        $providerFailed = $this->isThirdParty() && is_array($body) && ($body['success'] ?? null) === false;

        if ($response->failed() || isset($body['error']) || $providerFailed) {
            $exception = MetaException::fromResponse($body, $response->status());

            Log::error($this->isThirdParty() ? 'Third-party WhatsApp API error' : 'Meta Graph API error', [
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
