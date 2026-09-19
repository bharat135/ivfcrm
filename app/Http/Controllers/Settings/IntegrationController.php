<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMetaAccountJob;
use App\Models\Center;
use App\Models\ChannelAccount;
use App\Models\WebhookEvent;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use App\Services\Meta\MetaSyncService;
use App\Services\Meta\WhatsAppService;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Settings → Integrations.
 *
 * Where the user pastes their Meta App ID/secret, system-user access token,
 * WABA + phone number ids, ad account and page. Secrets are written through
 * SettingsRepository, which encrypts them; the form only ever renders a mask,
 * and a blank secret field means "leave unchanged".
 */
class IntegrationController extends Controller
{
    public function __construct(
        private SettingsRepository $settings,
        private MetaClient $client,
        private WhatsAppService $whatsapp,
        private MetaAdsService $ads,
        private MetaSyncService $sync,
    ) {}

    public function index()
    {
        return view('settings.integrations.index', [
            'meta'         => $this->settings->masked('meta'),
            'raw'          => $this->settings->all('meta'),
            'accounts'     => ChannelAccount::with('center')->orderBy('channel')->get(),
            'centers'      => Center::where('is_active', true)->orderBy('name')->get(),
            'webhookUrl'   => url('/api/webhooks/meta'),
            'lastSync'     => Cache::get('meta.last_sync'),
            'recentEvents' => WebhookEvent::where('source', 'meta')->latest()->limit(15)->get(),
            'status'       => [
                'connected' => $this->client->isConfigured(),
                'whatsapp'  => $this->client->isWhatsAppConfigured(),
                'ads'       => $this->client->isAdsConfigured(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_id'               => ['nullable', 'string', 'max:64'],
            'app_secret'           => ['nullable', 'string', 'max:255'],
            'access_token'         => ['nullable', 'string', 'max:1000'],
            'business_id'          => ['nullable', 'string', 'max:64'],
            'ad_account_id'        => ['nullable', 'string', 'max:64'],
            'page_id'              => ['nullable', 'string', 'max:64'],
            'waba_id'              => ['nullable', 'string', 'max:64'],
            'phone_number_id'      => ['nullable', 'string', 'max:64'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'pixel_id'             => ['nullable', 'string', 'max:64'],
            'default_country_code' => ['nullable', 'string', 'max:5'],

            // Conversions API. `dataset_id` is the Events Manager dataset that
            // receives the funnel; it defaults to the pixel when left blank.
            'dataset_id'           => ['nullable', 'string', 'max:64'],
            'test_event_code'      => ['nullable', 'string', 'max:64'],
            'treatment_value'      => ['nullable', 'numeric', 'min:0'],
            'currency'             => ['nullable', 'string', 'max:5'],
        ]);

        // A masked value coming back unchanged must not overwrite the real one.
        foreach ($data as $key => $value) {
            if (is_string($value) && str_starts_with($value, '••••')) {
                unset($data[$key]);
            }
        }

        $this->settings->setMany($data, 'meta');

        return response()->json([
            'success' => true,
            'message' => 'Integration settings saved.',
        ]);
    }

    /**
     * Probe each configured edge and report what actually works. This is the
     * fastest way for a user to tell a bad token from a missing permission.
     */
    public function test(): JsonResponse
    {
        $results = [];

        $results['token'] = $this->probe(function () {
            $me = $this->client->get('me', ['fields' => 'id,name']);

            return 'Connected as ' . ($me['name'] ?? $me['id'] ?? 'unknown');
        });

        if ($this->client->adAccountId()) {
            $results['ad_account'] = $this->probe(function () {
                $account = $this->ads->accountInfo();

                return ($account['name'] ?? 'Ad account') . ' · ' . ($account['currency'] ?? '')
                    . ' · status ' . ($account['account_status'] ?? '?');
            });
        }

        if ($this->client->pageId()) {
            $results['page'] = $this->probe(function () {
                $page = $this->client->get($this->client->pageId(), ['fields' => 'id,name,category']);

                return ($page['name'] ?? 'Page') . ' (' . ($page['category'] ?? 'page') . ')';
            });
        }

        if ($this->client->pageId()) {
            $results['lead_ads'] = $this->probe(function () {
                if (! $this->ads->hasPageToken()) {
                    throw new MetaException('No Page access token saved — click "Discover" on the Ads tab so Lead Ads submissions can be read.');
                }

                if (! $this->ads->isPageSubscribedToLeadgen()) {
                    throw new MetaException('Page is not subscribed to leadgen, so Lead Ads submissions never reach the webhook. Click "Subscribe Page to Lead Ads" on the Ads tab.');
                }

                return 'Page is subscribed to leadgen — new Lead Ads submissions reach the webhook.';
            });
        }

        if ($this->client->wabaId()) {
            $results['whatsapp'] = $this->probe(function () {
                $numbers = $this->whatsapp->phoneNumbers();

                if (! $numbers) {
                    return 'WABA reachable, but no phone numbers found.';
                }

                $first = $numbers[0];

                return ($first['display_phone_number'] ?? '') . ' · ' . ($first['verified_name'] ?? '')
                    . ' · quality ' . ($first['quality_rating'] ?? 'n/a');
            });
        }

        if ($this->client->phoneNumberId()) {
            $results['webhook'] = $this->probe(function () {
                $apps = $this->whatsapp->subscribedApps();

                return $apps
                    ? 'Webhook subscription active (' . count($apps) . ' app).'
                    : 'No app is subscribed to this WABA yet — click "Subscribe webhook".';
            });
        }

        if (! $this->client->credential('app_secret')) {
            $results['signature'] = [
                'ok'      => false,
                'message' => 'App Secret is empty, so incoming webhooks cannot be signature-verified. Add it.',
            ];
        }

        return response()->json(['success' => true, 'results' => $results]);
    }

    private function probe(callable $callback): array
    {
        try {
            return ['ok' => true, 'message' => $callback()];
        } catch (MetaException $e) {
            return ['ok' => false, 'message' => $e->displayMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Tell Meta to start delivering this WABA's events to our webhook.
     */
    public function subscribeWebhook(): JsonResponse
    {
        try {
            $this->whatsapp->subscribeWebhook();

            return response()->json(['success' => true, 'message' => 'Webhook subscribed for this WhatsApp Business Account.']);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }
    }

    /**
     * Tell Meta to start delivering this Page's Lead Ads submissions (the
     * `leadgen` field) to our webhook. Without this the CRM only ever sees
     * leads through the periodic poll.
     */
    public function subscribePage(): JsonResponse
    {
        try {
            $this->ads->subscribePage();

            return response()->json([
                'success' => true,
                'message' => 'Page subscribed. New Lead Ads submissions will now arrive through the webhook in real time.',
            ]);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }
    }

    /**
     * Populate the ad-account / page dropdowns from what the token can see.
     */
    public function discover(): JsonResponse
    {
        try {
            return response()->json([
                'success'     => true,
                'ad_accounts' => $this->ads->availableAdAccounts(),
                'pages'       => $this->ads->availablePages(),
            ]);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }
    }

    /**
     * Persist the long-lived Page tokens needed to read Lead Ads submissions.
     */
    public function savePageTokens(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tokens'   => ['required', 'array'],
            'tokens.*' => ['string'],
        ]);

        $this->settings->set('meta', 'page_tokens', $data['tokens']);

        return response()->json(['success' => true, 'message' => 'Page access tokens saved.']);
    }

    /**
     * Kick off a manual sync from the settings screen.
     */
    public function sync(Request $request): JsonResponse
    {
        $parts = $request->input('parts', ['structure', 'insights', 'forms', 'templates']);

        SyncMetaAccountJob::dispatch((array) $parts);

        return response()->json([
            'success' => true,
            'message' => 'Sync queued. Results appear here once the queue worker processes it.',
        ]);
    }

    // -----------------------------------------------------------------
    // Channel accounts (per-center WhatsApp numbers)
    // -----------------------------------------------------------------

    public function storeAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:191'],
            'channel'         => ['required', 'string', 'max:30'],
            'provider'        => ['required', 'string', 'max:40'],
            'center_id'       => ['nullable', 'exists:centers,id'],
            'identifier'      => ['nullable', 'string', 'max:191'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'waba_id'         => ['nullable', 'string', 'max:64'],
            'access_token'    => ['nullable', 'string', 'max:1000'],
            'is_default'      => ['boolean'],
            'is_active'       => ['boolean'],
        ]);

        $credentials = array_filter([
            'phone_number_id' => $data['phone_number_id'] ?? null,
            'waba_id'         => $data['waba_id'] ?? null,
            'access_token'    => $data['access_token'] ?? null,
        ]);

        $account = ChannelAccount::create([
            'name'        => $data['name'],
            'channel'     => $data['channel'],
            'provider'    => $data['provider'],
            'center_id'   => $data['center_id'] ?? null,
            'identifier'  => $data['identifier'] ?? null,
            'credentials' => $credentials ?: null,
            'is_default'  => $request->boolean('is_default'),
            'is_active'   => $request->boolean('is_active', true),
        ]);

        if ($account->is_default) {
            ChannelAccount::where('channel', $account->channel)
                ->whereKeyNot($account->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['success' => true, 'message' => 'Channel account added.']);
    }

    public function updateAccount(Request $request, ChannelAccount $account): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:191'],
            'center_id'       => ['nullable', 'exists:centers,id'],
            'identifier'      => ['nullable', 'string', 'max:191'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
            'waba_id'         => ['nullable', 'string', 'max:64'],
            'access_token'    => ['nullable', 'string', 'max:1000'],
            'is_default'      => ['boolean'],
            'is_active'       => ['boolean'],
        ]);

        $credentials = $account->credentials ?? [];

        foreach (['phone_number_id', 'waba_id', 'access_token'] as $key) {
            if (! empty($data[$key])) {
                $credentials[$key] = $data[$key];
            }
        }

        $account->update([
            'name'        => $data['name'],
            'center_id'   => $data['center_id'] ?? null,
            'identifier'  => $data['identifier'] ?? null,
            'credentials' => $credentials ?: null,
            'is_default'  => $request->boolean('is_default'),
            'is_active'   => $request->boolean('is_active', true),
        ]);

        if ($account->is_default) {
            ChannelAccount::where('channel', $account->channel)
                ->whereKeyNot($account->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['success' => true, 'message' => 'Channel account updated.']);
    }

    public function destroyAccount(ChannelAccount $account): JsonResponse
    {
        if ($account->conversations()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete: this account has conversations. Deactivate it instead.',
            ], 422);
        }

        $account->delete();

        return response()->json(['success' => true, 'message' => 'Channel account removed.']);
    }

    public function editAccount(ChannelAccount $account): JsonResponse
    {
        return response()->json([
            'id'         => $account->id,
            'name'       => $account->name,
            'channel'    => $account->channel,
            'provider'   => $account->provider,
            'center_id'  => $account->center_id,
            'identifier' => $account->identifier,
            'is_default' => $account->is_default,
            'is_active'  => $account->is_active,
            // Credentials are never echoed back; blank means "keep".
            'phone_number_id' => $account->credentials['phone_number_id'] ?? null,
            'waba_id'         => $account->credentials['waba_id'] ?? null,
        ]);
    }
}
