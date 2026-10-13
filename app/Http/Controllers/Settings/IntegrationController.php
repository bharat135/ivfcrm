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
            'provider'     => $provider = ChannelAccount::thirdPartyWhatsApp(),
            'providerWebhookUrl' => $provider && ! empty($provider->credentials['webhook_token'])
                ? ThirdPartyWhatsAppController::callbackUrl($provider)
                : null,
            'providerEvents' => WebhookEvent::where('source', ChannelAccount::PROVIDER_ONEAUTOMATIONS)->latest()->limit(10)->get(),
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

                try {
                    $subscribed = $this->ads->isPageSubscribedToLeadgen();
                } catch (MetaException $e) {
                    // Even reading the subscription needs pages_manage_metadata,
                    // so explain the token rather than echo Meta's bare error.
                    throw new MetaException($this->pageMetadataHint($e));
                }

                if (! $subscribed) {
                    throw new MetaException('Page is not subscribed to leadgen, so Lead Ads submissions never reach the webhook. Click "Subscribe Page to Lead Ads" on the Ads tab.');
                }

                return 'Page is subscribed to leadgen — new Lead Ads submissions reach the webhook.';
            });
        }

        if ($this->client->wabaId() || $this->client->phoneNumberId()) {
            $results['scopes'] = $this->probe(fn () => $this->tokenScopeStatus());
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
            $results['phone_number'] = $this->probe(fn () => $this->phoneNumberStatus(
                $this->client->phoneNumberId(),
                null,
                'Settings → Integrations → WhatsApp → Phone Number ID',
            ));
        }

        $senders = ChannelAccount::where('channel', 'whatsapp')->where('is_active', true)->get();

        if ($senders->isNotEmpty()) {
            $results['senders'] = $this->probe(fn () => $this->senderStatus($senders));
        }

        if ($this->client->phoneNumberId()) {
            $results['webhook'] = $this->probe(function () {
                try {
                    $apps = $this->whatsapp->subscribedApps();
                } catch (MetaException $e) {
                    if (! $e->isTransient()) {
                        throw $e;
                    }

                    // Meta's GET {waba}/subscribed_apps hangs ~30s and returns
                    // "(#1) An unknown error occurred" for some WABAs even when
                    // the subscription is fine (the POST succeeds). The app's
                    // own webhook configuration is the next best truth.
                    return $this->webhookStatusFromApp($e);
                }

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

    /**
     * The callback returns a message (ok) or a ready-made result row, which
     * lets a check report "working, with a caveat" as a warning.
     */
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

    /**
     * Sending needs whatsapp_business_messaging; reading the WABA (numbers,
     * templates, webhook) needs whatsapp_business_management. A token minted
     * before those permissions were added to the app lacks them silently —
     * Graph then answers every send with "Object with ID … does not exist".
     */
    private function tokenScopeStatus(): array
    {
        $info   = $this->client->debugToken();
        $scopes = $info['scopes'] ?? [];
        $missing = array_values(array_diff(['whatsapp_business_messaging', 'whatsapp_business_management'], $scopes));

        $issued = ! empty($info['issued_at']) ? date('d M Y', (int) $info['issued_at']) : null;
        $type   = $info['type'] ?? 'unknown';
        $who    = $type . ' token' . ($issued ? ' issued ' . $issued : '');

        if ($missing) {
            return [
                'ok'      => false,
                'message' => ucfirst($who) . ' is missing ' . implode(' and ', $missing)
                    . '. WhatsApp sends will fail with "Object with ID … does not exist". Generate a new System User token in '
                    . 'Meta Business Settings → System Users → Generate token, tick both WhatsApp permissions and assign the WABA '
                    . 'to that system user, then paste it here and save.',
            ];
        }

        if (empty($scopes)) {
            return ['ok' => true, 'warn' => true, 'message' => ucfirst($who) . ' — Meta did not return its scopes, so permissions could not be verified.'];
        }

        return ucfirst($who) . ' has both WhatsApp permissions (' . count($scopes) . ' scopes).';
    }

    /**
     * Prove the phone number id can be used with the token: it must load, and
     * it should be one of the numbers Meta lists under the WABA. A Page ID,
     * WABA ID or a number from another business pasted into the field is the
     * usual reason outbound messages fail while inbound still arrive.
     */
    private function phoneNumberStatus(string $phoneNumberId, ?ChannelAccount $account, string $where): array|string
    {
        try {
            $number = $this->client->forAccount($account)->get(
                $phoneNumberId,
                ['fields' => 'id,display_phone_number,verified_name,status,code_verification_status,quality_rating'],
            );
        } catch (MetaException $e) {
            $hint = $this->listedNumbersHint($account);

            return [
                'ok'      => false,
                'message' => 'Phone Number ID ' . $phoneNumberId . ' (' . $where . ') cannot be loaded with this token: '
                    . $e->displayMessage() . $hint,
            ];
        }

        $label = ($number['display_phone_number'] ?? $phoneNumberId) . ' · ' . ($number['verified_name'] ?? '')
            . ' · ' . ($number['status'] ?? $number['code_verification_status'] ?? 'status n/a');

        $status = strtoupper((string) ($number['status'] ?? ''));

        if ($status && ! in_array($status, ['CONNECTED', 'VERIFIED'], true)) {
            return [
                'ok'      => true,
                'warn'    => true,
                'message' => $label . ' — the number is not CONNECTED, so Meta may reject sends. Check WhatsApp Manager → Phone numbers.',
            ];
        }

        return $label;
    }

    private function listedNumbersHint(?ChannelAccount $account): string
    {
        try {
            $listed = collect($this->whatsapp->phoneNumbers($account))
                ->map(fn ($n) => ($n['display_phone_number'] ?? '?') . ' = ' . ($n['id'] ?? '?'))
                ->implode(', ');
        } catch (\Throwable) {
            return '';
        }

        return $listed
            ? ' The WABA lists: ' . $listed . '. Paste the matching ID.'
            : ' The WABA lists no phone numbers for this token.';
    }

    /**
     * Each active WhatsApp sender (Settings → Integrations → Channel accounts)
     * may override the phone number id. The inbox always sends through the
     * conversation's own account, so a stale override breaks replies even
     * when the global Phone Number ID is right.
     */
    private function senderStatus($senders): array|string
    {
        $lines = [];
        $bad   = false;
        $warn  = false;

        foreach ($senders as $account) {
            $own = $account->credentials['phone_number_id'] ?? null;
            $id  = $own ?: $this->client->phoneNumberId();

            if (! $id) {
                $lines[] = $account->name . ': no Phone Number ID (neither on the account nor globally)';
                $bad = true;
                continue;
            }

            $result = $this->phoneNumberStatus($id, $account, 'sender "' . $account->name . '"' . ($own ? '' : ', inherited'));

            if (is_array($result)) {
                $bad  = $bad || ! $result['ok'];
                $warn = $warn || ! empty($result['warn']);
                $lines[] = $result['message'];
            } else {
                $lines[] = $account->name . ($account->is_default ? ' (default)' : '') . ': ' . $result;
            }
        }

        $message = implode(' | ', $lines);

        return $bad
            ? ['ok' => false, 'message' => $message]
            : ($warn ? ['ok' => true, 'warn' => true, 'message' => $message] : $message);
    }

    /**
     * Judge the WhatsApp webhook from the app's configuration when Meta
     * cannot read the WABA's subscribed_apps list.
     */
    private function webhookStatusFromApp(MetaException $readBack): array
    {
        $subscription = $this->whatsapp->appWebhookSubscription();
        $why          = 'Meta could not list this WABA\'s subscribed apps (their error ' . ($readBack->errorCode ?? '?') . ': ' . $readBack->getMessage() . ').';

        if (! $subscription) {
            throw new MetaException($why . ' The app has no whatsapp_business_account webhook either — set the callback URL and verify token under Meta App Dashboard → WhatsApp → Configuration, then click "Subscribe webhook".');
        }

        $callback = $subscription['callback_url'] ?? '';
        $fields   = array_column($subscription['fields'] ?? [], 'name');

        if (empty($subscription['active'])) {
            throw new MetaException($why . ' The app\'s whatsapp_business_account webhook exists but is inactive — re-verify it in the Meta App Dashboard.');
        }

        if (rtrim($callback, '/') !== rtrim(url('/api/webhooks/meta'), '/')) {
            throw new MetaException($why . ' The app\'s webhook points at ' . $callback . ', not this CRM — change the callback URL in the Meta App Dashboard.');
        }

        if (! in_array('messages', $fields, true)) {
            throw new MetaException($why . ' The app\'s webhook is active but not subscribed to the "messages" field — tick it under Webhook fields in the Meta App Dashboard.');
        }

        $lastEvent = WebhookEvent::where('source', 'meta')->latest()->first();
        $evidence  = $lastEvent
            ? 'Last event received ' . $lastEvent->created_at->diffForHumans() . '.'
            : 'No event has arrived yet — send a WhatsApp message to the business number to confirm end-to-end.';

        return [
            'ok'      => true,
            'warn'    => true,
            'message' => 'Webhook is configured and active for this app (' . implode(', ', $fields) . '). '
                . $evidence . ' ' . $why,
        ];
    }

    /**
     * Meta's "(#200) Requires pages_manage_metadata permission" hides the
     * real fix: the saved token predates that permission. Say what the token
     * actually carries so the user can see the gap.
     */
    private function pageMetadataHint(MetaException $e): string
    {
        $message = $e->displayMessage();

        if (! str_contains($e->getMessage(), 'pages_manage_metadata')) {
            return $message;
        }

        $message = rtrim($message, '. ') . '.';

        $issued = null;
        $scopes = [];

        try {
            $info   = $this->client->debugToken();
            $scopes = $info['scopes'] ?? [];
            $issued = ! empty($info['issued_at']) ? date('d M Y', (int) $info['issued_at']) : null;
        } catch (\Throwable) {
            // Best effort only; the advice below stands without it.
        }

        $detail = $scopes
            ? ' The saved token' . ($issued ? " (issued $issued)" : '') . ' carries: ' . implode(', ', $scopes) . ' — pages_manage_metadata is not among them.'
            : '';

        return $message . $detail
            . ' Adding the permission to the app does not change tokens already issued: generate a new system-user token in Meta Business Settings '
            . 'with pages_manage_metadata, pages_read_engagement and leads_retrieval, save it on the Meta tab, click Discover (which refreshes the Page token), then try again. '
            . 'Until then leads still arrive through the 15-minute poll.';
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
            return response()->json(['success' => false, 'message' => $this->pageMetadataHint($e)], 422);
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
            'app_id'          => ['nullable', 'string', 'max:64'],
            'app_secret'      => ['nullable', 'string', 'max:255'],
            'is_default'      => ['boolean'],
            'is_active'       => ['boolean'],
        ]);

        $credentials = array_filter([
            'phone_number_id' => $data['phone_number_id'] ?? null,
            'waba_id'         => $data['waba_id'] ?? null,
            'access_token'    => $data['access_token'] ?? null,
            'app_id'          => $data['app_id'] ?? null,
            'app_secret'      => $data['app_secret'] ?? null,
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
            'app_id'          => ['nullable', 'string', 'max:64'],
            'app_secret'      => ['nullable', 'string', 'max:255'],
            'clear_app'       => ['boolean'],
            'is_default'      => ['boolean'],
            'is_active'       => ['boolean'],
        ]);

        $credentials = $account->credentials ?? [];

        // Ids are shown in the form, so an emptied field means "remove the
        // override"; secrets are never echoed, so blank there means "keep".
        foreach (['phone_number_id', 'waba_id', 'app_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $credentials[$key] = $data[$key] ?: null;
            }
        }

        foreach (['access_token', 'app_secret'] as $key) {
            if (! empty($data[$key])) {
                $credentials[$key] = $data[$key];
            }
        }

        // "Back to the shared app": drop the app-level overrides together so
        // a stale secret can never be paired with the shared token.
        if ($request->boolean('clear_app')) {
            unset($credentials['app_id'], $credentials['app_secret']);
        }

        $credentials = array_filter($credentials, fn ($v) => $v !== null && $v !== '');

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
            // Secrets are never echoed back; blank means "keep".
            'phone_number_id'  => $account->credentials['phone_number_id'] ?? null,
            'waba_id'          => $account->credentials['waba_id'] ?? null,
            'app_id'           => $account->credentials['app_id'] ?? null,
            'has_access_token' => ! empty($account->credentials['access_token']),
            'has_app_secret'   => ! empty($account->credentials['app_secret']),
        ]);
    }
}
