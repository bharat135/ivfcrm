<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use App\Services\Meta\MetaSyncService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Settings → Integrations → "Third-party WhatsApp API (1automations)".
 *
 * A temporary sending route alongside the direct Meta Cloud API, which stays
 * untouched. The credentials live in one channel_accounts row (encrypted), so
 * once it is the active default sender every WhatsApp feature — inbox,
 * profile chat, templates, media, broadcasts — goes through it.
 */
class ThirdPartyWhatsAppController extends Controller
{
    /** Events requested when registering the CRM webhook with the provider. */
    private const EVENTS = ['incoming', 'sent', 'delivered', 'read', 'failed', 'deleted', 'template', 'account', 'phone_number', 'outgoing'];

    public function __construct(private MetaClient $client) {}

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:191'],
            'identifier'      => ['nullable', 'string', 'max:191'],
            'access_token'    => ['nullable', 'string', 'max:2000'],
            'app_url'         => ['required', 'url', 'max:255'],
            'api_version'     => ['nullable', 'string', 'max:20', 'regex:/^v?\d+(\.\d+)?$/'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'waba_id'         => ['nullable', 'string', 'max:64'],
            'business_id'     => ['nullable', 'string', 'max:64'],
            'webhook_api_url' => ['nullable', 'url', 'max:255'],
            'is_active'       => ['boolean'],
            'is_default'      => ['boolean'],
        ]);

        $account     = ChannelAccount::thirdPartyWhatsApp();
        $credentials = $account?->credentials ?? [];

        foreach (['app_url', 'api_version', 'phone_number_id', 'waba_id', 'business_id', 'webhook_api_url'] as $key) {
            $credentials[$key] = isset($data[$key]) && $data[$key] !== '' ? trim($data[$key]) : null;
        }

        $credentials['app_url'] = ChannelAccount::normaliseProviderUrl($credentials['app_url']);

        if (! empty($credentials['api_version']) && ! str_starts_with($credentials['api_version'], 'v')) {
            $credentials['api_version'] = 'v' . $credentials['api_version'];
        }

        // The key is never echoed back; blank means "keep the saved one".
        if (! empty($data['access_token'])) {
            $credentials['access_token'] = trim($data['access_token']);
        }

        if (empty($credentials['access_token'])) {
            return response()->json(['success' => false, 'message' => 'Enter the access key.'], 422);
        }

        $credentials['webhook_token'] = $credentials['webhook_token'] ?? Str::random(40);
        $credentials = array_filter($credentials, fn ($v) => $v !== null && $v !== '');

        $attributes = [
            'channel'     => 'whatsapp',
            'provider'    => ChannelAccount::PROVIDER_ONEAUTOMATIONS,
            'name'        => $data['name'],
            'identifier'  => $data['identifier'] ?? null,
            'credentials' => $credentials,
            'is_active'   => $request->boolean('is_active', true),
            'is_default'  => $request->boolean('is_default'),
        ];

        if ($account) {
            $account->update($attributes);
        } else {
            $account = ChannelAccount::create($attributes);
        }

        if ($account->is_default) {
            ChannelAccount::where('channel', 'whatsapp')->whereKeyNot($account->id)->update(['is_default' => false]);
        }

        return response()->json([
            'success'     => true,
            'message'     => 'Third-party WhatsApp API saved.' . ($account->is_default && $account->is_active
                ? ' It is now the default WhatsApp sender.'
                : ' It is not the default sender yet, so WhatsApp still goes out through the other account.'),
            'webhook_url' => $this->callbackUrl($account),
        ]);
    }

    /**
     * Check the key and URL against the provider: channel info, the phone
     * number, and the template list.
     */
    public function test(): JsonResponse
    {
        $account = $this->account();
        $client  = $this->client->forAccount($account);
        $cr      = $account->credentials;
        $results = [];

        // The provider's /info endpoint errors, so the business profile of the
        // number is the connectivity check: it proves the URL, version, key
        // and that the Phone Number ID belongs to this key.
        $results['phone_number'] = $this->probe(function () use ($client, $cr) {
            $profile = $client->get($cr['phone_number_id'] . '/whatsapp_business_profile', ['fields' => 'about,description,vertical']);
            $about   = $profile['data'][0]['about'] ?? $profile['data'][0]['description'] ?? null;

            return 'Access key accepted for Phone Number ID ' . $cr['phone_number_id'] . ($about ? ' · "' . mb_substr($about, 0, 60) . '"' : '') . '.';
        });

        if (! empty($cr['waba_id'])) {
            $results['templates'] = $this->probe(function () use ($client, $cr) {
                $list = $client->get($cr['waba_id'] . '/message_templates', ['limit' => 100, 'offset' => 0]);
                $rows = $list['data'] ?? (array_is_list($list) ? $list : []);
                $approved = collect($rows)->filter(fn ($t) => strtoupper($t['status'] ?? '') === 'APPROVED')->count();

                return count($rows) . ' template(s), ' . $approved . ' approved.';
            });
        }

        $results['sender'] = ['ok' => true, 'warn' => ! ($account->is_active && $account->is_default), 'message' => $account->is_active && $account->is_default
            ? 'Active default sender — all WhatsApp messages go out through this API.'
            : 'Saved but not the active default sender, so messages still use the other account.'];

        return response()->json(['success' => true, 'results' => $results]);
    }

    /**
     * Create (or update) the provider-side webhook that posts events to the
     * CRM: POST/PUT {webhook_api_url}/v2/webhook.
     */
    public function registerWebhook(): JsonResponse
    {
        $account = $this->account();
        $cr      = $account->credentials;
        $base    = $this->webhookApiBase($account);

        $body = [
            'title'  => 'IVF CRM (' . parse_url(config('app.url'), PHP_URL_HOST) . ')',
            'active' => true,
            'events' => self::EVENTS,
            'url'    => $this->callbackUrl($account),
        ];

        $response = ! empty($cr['webhook_id'])
            ? $this->webhookApi($account)->put($base . '/v2/webhook/' . $cr['webhook_id'], $body)
            : $this->webhookApi($account)->post($base . '/v2/webhook', $body);

        // A stale id (deleted on the provider) — create a fresh one.
        if ($response->status() === 404 && ! empty($cr['webhook_id'])) {
            $response = $this->webhookApi($account)->post($base . '/v2/webhook', $body);
        }

        if ($response->failed()) {
            return response()->json(['success' => false, 'message' => $this->providerError($response)], 422);
        }

        $json = $response->json() ?? [];
        $id   = $json['id'] ?? $json['_id'] ?? $json['data']['id'] ?? $json['data']['_id'] ?? $json['webhook']['id'] ?? $cr['webhook_id'] ?? null;

        if ($id) {
            $cr['webhook_id'] = (string) $id;
            $account->update(['credentials' => $cr]);
        }

        return response()->json([
            'success'  => true,
            'message'  => 'Webhook registered with the provider' . ($id ? ' (id ' . $id . ')' : '') . '. Incoming messages and delivery ticks will now reach the CRM.',
            'response' => $json,
        ]);
    }

    public function listWebhooks(): JsonResponse
    {
        $account  = $this->account();
        $response = $this->webhookApi($account)->get($this->webhookApiBase($account) . '/v2/webhook');

        if ($response->failed()) {
            return response()->json(['success' => false, 'message' => $this->providerError($response)], 422);
        }

        return response()->json(['success' => true, 'webhooks' => $response->json(), 'ours' => $this->callbackUrl($account)]);
    }

    public function syncTemplates(MetaSyncService $sync): JsonResponse
    {
        $account = $this->account();

        if (empty($account->credentials['waba_id'])) {
            return response()->json(['success' => false, 'message' => 'Enter the WABA ID first.'], 422);
        }

        try {
            $count = $sync->syncTemplates($account);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => $count . ' template(s) synced from the provider.']);
    }

    // -----------------------------------------------------------------

    public static function callbackUrl(ChannelAccount $account): string
    {
        return url('/api/webhooks/whatsapp-provider/' . ($account->credentials['webhook_token'] ?? ''));
    }

    private function account(): ChannelAccount
    {
        $account = ChannelAccount::thirdPartyWhatsApp();

        abort_unless($account && ! empty($account->credentials['access_token']), 422, 'Save the third-party WhatsApp API settings first.');

        return $account;
    }

    private function webhookApiBase(ChannelAccount $account): string
    {
        return rtrim($account->credentials['webhook_api_url'] ?? $account->credentials['app_url'], '/');
    }

    private function webhookApi(ChannelAccount $account)
    {
        return Http::withToken($account->credentials['access_token'])
            ->acceptJson()
            ->timeout(config('meta.timeout'));
    }

    private function providerError(Response $response): string
    {
        $json = $response->json() ?? [];
        $msg  = $json['error']['message'] ?? (is_string($json['error'] ?? null) ? $json['error'] : null) ?? ($json['message'] ?? null);

        return 'Provider answered HTTP ' . $response->status() . ($msg ? ': ' . $msg : '') .
            ($response->status() === 404 ? ' — check the "Webhook API URL" (the provider\'s {{base-url}}).' : '');
    }

    private function probe(callable $callback): array
    {
        try {
            $result = $callback();

            return is_array($result) ? $result : ['ok' => true, 'message' => $result];
        } catch (MetaException $e) {
            return ['ok' => false, 'message' => $e->displayMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
