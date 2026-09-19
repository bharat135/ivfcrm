<?php

namespace App\Services\Google;

use App\Support\SettingsRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for the Google Ads API.
 *
 * Deliberately mirrors MetaClient: credentials come from Settings at call time
 * so the clinic can paste a new refresh token without a deploy, and every
 * request goes through one place that knows how to authenticate, translate an
 * error and keep the token out of the log.
 *
 * Google differs from Meta in one important way — the API is authorised with a
 * short-lived OAuth access token minted from a long-lived refresh token, so the
 * mint-and-cache step lives here.
 */
class GoogleAdsClient
{
    private const TOKEN_CACHE_KEY = 'google_ads.access_token';

    public function __construct(private SettingsRepository $settings) {}

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    public function credential(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get('google_ads', $key);

        if ($value === null || $value === '') {
            return config("google_ads.defaults.$key") ?? $default;
        }

        return $value;
    }

    /** Google wants the customer id as bare digits, but people paste it dashed. */
    public function customerId(): ?string
    {
        $id = $this->credential('customer_id');

        return $id ? preg_replace('/\D/', '', (string) $id) : null;
    }

    public function loginCustomerId(): ?string
    {
        $id = $this->credential('login_customer_id');

        return $id ? preg_replace('/\D/', '', (string) $id) : null;
    }

    /**
     * Everything needed before a single call can be made. Checked by the UI so
     * the integration can say what is still missing instead of failing later.
     *
     * @return array<int,string> Human-readable names of the missing pieces.
     */
    public function missingCredentials(): array
    {
        $required = [
            'client_id'       => 'OAuth client ID',
            'client_secret'   => 'OAuth client secret',
            'refresh_token'   => 'OAuth refresh token',
            'developer_token' => 'Developer token',
            'customer_id'     => 'Customer ID',
        ];

        $missing = [];

        foreach ($required as $key => $label) {
            if (blank($this->credential($key))) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingCredentials() === [];
    }

    // -----------------------------------------------------------------
    // OAuth
    // -----------------------------------------------------------------

    /**
     * A Google access token lives one hour. It is cached for slightly less so
     * a long-running batch never trips over an expiry mid-flight.
     */
    public function accessToken(bool $fresh = false): ?string
    {
        if ($fresh) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, 3300, function () {
            $response = Http::asForm()
                ->timeout(config('google_ads.timeout'))
                ->post(config('google_ads.oauth_url'), [
                    'client_id'     => $this->credential('client_id'),
                    'client_secret' => $this->credential('client_secret'),
                    'refresh_token' => $this->credential('refresh_token'),
                    'grant_type'    => 'refresh_token',
                ]);

            if ($response->failed()) {
                // A revoked refresh token is the usual cause and needs a human,
                // so it is reported rather than retried forever.
                throw new GoogleAdsException(
                    'Google refused to mint an access token: ' . $this->reason($response->json() ?? []),
                    $response->status(),
                );
            }

            return $response->json('access_token');
        });
    }

    // -----------------------------------------------------------------
    // Verbs
    // -----------------------------------------------------------------

    public function post(string $path, array $payload = []): array
    {
        $url = $this->url($path);

        return $this->handle($this->request()->post($url, $payload), 'POST', $url);
    }

    public function get(string $path, array $query = []): array
    {
        $url = $this->url($path);

        return $this->handle($this->request()->get($url, $query), 'GET', $url);
    }

    /**
     * Run a GAQL query against the account. Used to list conversion actions and
     * campaigns for the settings screen.
     */
    public function search(string $gaql, int $pageSize = 200): array
    {
        $customer = $this->customerId();

        if (! $customer) {
            throw new GoogleAdsException('Google Ads Customer ID is not configured. Add it under Settings → Integrations.');
        }

        $out  = [];
        $page = null;

        do {
            $body = array_filter([
                'query'     => $gaql,
                'pageSize'  => $pageSize,
                'pageToken' => $page,
            ]);

            $response = $this->post("customers/{$customer}/googleAds:search", $body);

            $out  = array_merge($out, $response['results'] ?? []);
            $page = $response['nextPageToken'] ?? null;
        } while ($page);

        return $out;
    }

    public function url(string $path): string
    {
        return rtrim(config('google_ads.api_url'), '/')
            . '/' . config('google_ads.api_version')
            . '/' . ltrim($path, '/');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function request(): PendingRequest
    {
        $headers = ['developer-token' => (string) $this->credential('developer_token')];

        // Only needed when the account is managed under an MCC.
        if ($login = $this->loginCustomerId()) {
            $headers['login-customer-id'] = $login;
        }

        return Http::withToken($this->accessToken())
            ->withHeaders($headers)
            ->timeout(config('google_ads.timeout'))
            ->retry(config('google_ads.retries'), 500, function ($exception) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
            ->acceptJson();
    }

    private function handle(Response $response, string $method, string $url): array
    {
        $body = $response->json() ?? [];

        if ($response->failed() || isset($body['error'])) {
            Log::error('Google Ads API error', [
                'method' => $method,
                'url'    => $url,
                'status' => $response->status(),
                'error'  => $this->reason($body),
            ]);

            throw new GoogleAdsException($this->reason($body), $response->status(), $body);
        }

        return $body;
    }

    /**
     * Google buries the useful sentence several levels down, and returns a
     * different shape for OAuth failures than for API failures.
     */
    private function reason(array $body): string
    {
        if (isset($body['error_description'])) {
            return (string) $body['error_description'];
        }

        $error = $body['error'] ?? [];

        $detail = $error['details'][0]['errors'][0] ?? null;

        if ($detail) {
            return (string) ($detail['message'] ?? 'Google Ads rejected the request.');
        }

        return (string) ($error['message'] ?? 'Google Ads rejected the request.');
    }
}
