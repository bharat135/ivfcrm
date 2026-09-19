<?php

namespace App\Services\Meta;

use Illuminate\Support\Carbon;

/**
 * Meta Marketing API — the ad account, campaign, ad set, ad, creative,
 * lead-form and insights edges.
 *
 * Money handling: Meta expresses every budget/bid in the ad account's *minor*
 * currency unit (paise for INR, cents for USD). The CRM stores and displays
 * major units, so toMinor()/fromMinor() bracket every boundary crossing.
 */
class MetaAdsService
{
    public function __construct(private MetaClient $client) {}

    private function account(): string
    {
        $id = $this->client->adAccountId();

        if (! $id) {
            throw new MetaException('Meta Ad Account ID is not configured. Add it under Settings → Integrations.');
        }

        return $id;
    }

    public function toMinor(int|float|null $amount): ?int
    {
        return $amount === null ? null : (int) round($amount * 100);
    }

    public function fromMinor(int|string|null $amount): ?float
    {
        return $amount === null || $amount === '' ? null : round(((float) $amount) / 100, 2);
    }

    // -----------------------------------------------------------------
    // Account
    // -----------------------------------------------------------------

    public function accountInfo(): array
    {
        return $this->client->get($this->account(), [
            'fields' => 'id,name,account_status,currency,timezone_name,amount_spent,balance,business_name,funding_source_details',
        ]);
    }

    /** Ad accounts the token can see — used to populate the settings dropdown. */
    public function availableAdAccounts(): array
    {
        return $this->client->paginate('me/adaccounts', [
            'fields' => 'id,name,account_status,currency,business_name',
        ], maxPages: 5);
    }

    /** Facebook Pages the token can manage — needed for creatives and lead forms. */
    public function availablePages(): array
    {
        return $this->client->paginate('me/accounts', [
            'fields' => 'id,name,access_token,category,tasks',
        ], maxPages: 5);
    }

    // -----------------------------------------------------------------
    // Campaigns
    // -----------------------------------------------------------------

    public const CAMPAIGN_FIELDS = 'id,name,objective,status,effective_status,buying_type,daily_budget,lifetime_budget,'
        . 'bid_strategy,special_ad_categories,start_time,stop_time,created_time,updated_time,account_id';

    public function listCampaigns(int $maxPages = 10): array
    {
        return $this->client->paginate($this->account() . '/campaigns', [
            'fields' => self::CAMPAIGN_FIELDS,
        ], $maxPages);
    }

    public function getCampaign(string $externalId): array
    {
        return $this->client->get($externalId, ['fields' => self::CAMPAIGN_FIELDS]);
    }

    /**
     * @param  array  $data  name, objective, status, daily_budget|lifetime_budget
     *                       (major units), bid_strategy, special_ad_categories
     */
    public function createCampaign(array $data): array
    {
        $payload = array_filter([
            'name'                  => $data['name'],
            'objective'             => $data['objective'] ?? 'OUTCOME_LEADS',
            // New campaigns start paused so nothing spends before it is reviewed.
            'status'                => $data['status'] ?? 'PAUSED',
            'buying_type'           => $data['buying_type'] ?? 'AUCTION',
            'daily_budget'          => $this->toMinor($data['daily_budget'] ?? null),
            'lifetime_budget'       => $this->toMinor($data['lifetime_budget'] ?? null),
            'bid_strategy'          => $data['bid_strategy'] ?? null,
            'special_ad_categories' => json_encode($this->normalizeSpecialCategories($data['special_ad_categories'] ?? [])),
        ], fn ($v) => $v !== null && $v !== '');

        // A lifetime budget at campaign level requires a stop time.
        if (! empty($data['lifetime_budget']) && ! empty($data['end_date'])) {
            $payload['stop_time'] = Carbon::parse($data['end_date'])->toIso8601String();
        }

        return $this->client->post($this->account() . '/campaigns', $payload);
    }

    public function updateCampaign(string $externalId, array $data): array
    {
        $payload = array_filter([
            'name'            => $data['name'] ?? null,
            'status'          => $data['status'] ?? null,
            'daily_budget'    => $this->toMinor($data['daily_budget'] ?? null),
            'lifetime_budget' => $this->toMinor($data['lifetime_budget'] ?? null),
            'bid_strategy'    => $data['bid_strategy'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->client->post($externalId, $payload);
    }

    /** Meta treats status=DELETED as the delete operation for ad objects. */
    public function deleteCampaign(string $externalId): array
    {
        return $this->client->delete($externalId);
    }

    public function setStatus(string $externalId, string $status): array
    {
        return $this->client->post($externalId, ['status' => $status]);
    }

    private function normalizeSpecialCategories(array|string|null $categories): array
    {
        $categories = is_string($categories) ? [$categories] : ($categories ?: []);

        return array_values(array_filter($categories, fn ($c) => $c && $c !== 'NONE'));
    }

    // -----------------------------------------------------------------
    // Ad sets
    // -----------------------------------------------------------------

    public const ADSET_FIELDS = 'id,name,campaign_id,status,effective_status,daily_budget,lifetime_budget,'
        . 'optimization_goal,billing_event,bid_amount,targeting,promoted_object,destination_type,'
        . 'start_time,end_time,created_time,updated_time';

    public function listAdsets(?string $campaignExternalId = null, int $maxPages = 10): array
    {
        $edge = $campaignExternalId
            ? $campaignExternalId . '/adsets'
            : $this->account() . '/adsets';

        return $this->client->paginate($edge, ['fields' => self::ADSET_FIELDS], $maxPages);
    }

    public function getAdset(string $externalId): array
    {
        return $this->client->get($externalId, ['fields' => self::ADSET_FIELDS]);
    }

    /**
     * @param  array  $data  campaign_id (external), name, optimization_goal,
     *                       billing_event, daily_budget, targeting, promoted_object,
     *                       destination_type, start_time, end_time, bid_amount
     */
    public function createAdset(array $data): array
    {
        $payload = array_filter([
            'name'              => $data['name'],
            'campaign_id'       => $data['campaign_id'],
            'status'            => $data['status'] ?? 'PAUSED',
            'optimization_goal' => $data['optimization_goal'] ?? 'LEAD_GENERATION',
            'billing_event'     => $data['billing_event'] ?? 'IMPRESSIONS',
            'daily_budget'      => $this->toMinor($data['daily_budget'] ?? null),
            'lifetime_budget'   => $this->toMinor($data['lifetime_budget'] ?? null),
            'bid_amount'        => $this->toMinor($data['bid_amount'] ?? null),
            'destination_type'  => $data['destination_type'] ?? null,
            'targeting'         => json_encode($this->buildTargeting($data['targeting'] ?? [])),
            'start_time'        => ! empty($data['start_time']) ? Carbon::parse($data['start_time'])->toIso8601String() : null,
            'end_time'          => ! empty($data['end_time']) ? Carbon::parse($data['end_time'])->toIso8601String() : null,
        ], fn ($v) => $v !== null && $v !== '');

        $promoted = $this->buildPromotedObject($data);
        if ($promoted) {
            $payload['promoted_object'] = json_encode($promoted);
        }

        return $this->client->post($this->account() . '/adsets', $payload);
    }

    public function updateAdset(string $externalId, array $data): array
    {
        $payload = array_filter([
            'name'            => $data['name'] ?? null,
            'status'          => $data['status'] ?? null,
            'daily_budget'    => $this->toMinor($data['daily_budget'] ?? null),
            'lifetime_budget' => $this->toMinor($data['lifetime_budget'] ?? null),
            'bid_amount'      => $this->toMinor($data['bid_amount'] ?? null),
            'end_time'        => ! empty($data['end_time']) ? Carbon::parse($data['end_time'])->toIso8601String() : null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! empty($data['targeting'])) {
            $payload['targeting'] = json_encode($this->buildTargeting($data['targeting']));
        }

        return $this->client->post($externalId, $payload);
    }

    public function deleteAdset(string $externalId): array
    {
        return $this->client->delete($externalId);
    }

    /**
     * Translate the CRM's simple targeting form into Meta's targeting spec.
     */
    public function buildTargeting(array $input): array
    {
        // A caller may already hold a full Meta spec (e.g. re-saving after sync).
        if (isset($input['geo_locations'])) {
            return $input;
        }

        $geo = [];

        if (! empty($input['countries'])) {
            $geo['countries'] = array_values((array) $input['countries']);
        }
        if (! empty($input['cities'])) {
            $geo['cities'] = array_map(fn ($city) => [
                'key'           => is_array($city) ? $city['key'] : $city,
                'radius'        => is_array($city) ? ($city['radius'] ?? 25) : 25,
                'distance_unit' => 'kilometer',
            ], (array) $input['cities']);
        }
        if (! empty($input['regions'])) {
            $geo['regions'] = array_map(fn ($r) => ['key' => is_array($r) ? $r['key'] : $r], (array) $input['regions']);
        }

        if ($geo === []) {
            $geo['countries'] = ['IN'];
        }

        $targeting = [
            'geo_locations' => $geo,
            'age_min'       => (int) ($input['age_min'] ?? 21),
            'age_max'       => (int) ($input['age_max'] ?? 45),
        ];

        // 0/empty = all genders; 1 = men, 2 = women.
        if (! empty($input['genders']) && (int) $input['genders'] !== 0) {
            $targeting['genders'] = [(int) $input['genders']];
        }

        if (! empty($input['interests'])) {
            $targeting['flexible_spec'] = [[
                'interests' => array_map(fn ($i) => [
                    'id'   => is_array($i) ? $i['id'] : $i,
                    'name' => is_array($i) ? ($i['name'] ?? '') : '',
                ], (array) $input['interests']),
            ]];
        }

        if (! empty($input['locales'])) {
            $targeting['locales'] = array_map('intval', (array) $input['locales']);
        }

        if (! empty($input['excluded_interests'])) {
            $targeting['exclusions'] = [
                'interests' => array_map(fn ($i) => [
                    'id'   => is_array($i) ? $i['id'] : $i,
                    'name' => is_array($i) ? ($i['name'] ?? '') : '',
                ], (array) $input['excluded_interests']),
            ];
        }

        $targeting['publisher_platforms'] = ! empty($input['platforms'])
            ? array_values((array) $input['platforms'])
            : ['facebook', 'instagram'];

        return $targeting;
    }

    /**
     * `promoted_object` tells Meta what the ad set optimises toward.
     */
    private function buildPromotedObject(array $data): ?array
    {
        $destination = $data['destination_type'] ?? null;
        $goal        = $data['optimization_goal'] ?? null;

        if ($destination === 'WHATSAPP' || $goal === 'CONVERSATIONS') {
            $pageId = $data['page_id'] ?? $this->client->pageId();

            return $pageId ? ['page_id' => $pageId] : null;
        }

        if ($goal === 'OFFSITE_CONVERSIONS' && ! empty($data['pixel_id'])) {
            return [
                'pixel_id'    => $data['pixel_id'],
                'custom_event_type' => $data['custom_event_type'] ?? 'LEAD',
            ];
        }

        if (in_array($goal, ['LEAD_GENERATION', 'QUALITY_LEAD'], true)) {
            $pageId = $data['page_id'] ?? $this->client->pageId();

            return $pageId ? ['page_id' => $pageId] : null;
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Ads + creatives
    // -----------------------------------------------------------------

    public const AD_FIELDS = 'id,name,adset_id,campaign_id,status,effective_status,creative{id,name,body,title,'
        . 'thumbnail_url,image_url,object_story_spec},created_time,updated_time';

    public function listAds(?string $adsetExternalId = null, int $maxPages = 10): array
    {
        $edge = $adsetExternalId
            ? $adsetExternalId . '/ads'
            : $this->account() . '/ads';

        return $this->client->paginate($edge, ['fields' => self::AD_FIELDS], $maxPages);
    }

    public function getAd(string $externalId): array
    {
        return $this->client->get($externalId, ['fields' => self::AD_FIELDS]);
    }

    public function createAd(array $data): array
    {
        return $this->client->post($this->account() . '/ads', array_filter([
            'name'      => $data['name'],
            'adset_id'  => $data['adset_id'],
            'status'    => $data['status'] ?? 'PAUSED',
            'creative'  => json_encode(['creative_id' => $data['creative_id']]),
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public function updateAd(string $externalId, array $data): array
    {
        $payload = array_filter([
            'name'   => $data['name'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! empty($data['creative_id'])) {
            $payload['creative'] = json_encode(['creative_id' => $data['creative_id']]);
        }

        return $this->client->post($externalId, $payload);
    }

    public function deleteAd(string $externalId): array
    {
        return $this->client->delete($externalId);
    }

    /**
     * Build the creative that an ad points at.
     *
     * @param  array  $data  page_id, message, headline, description, link,
     *                       image_hash, call_to_action, lead_form_id, whatsapp_number
     */
    public function createAdCreative(array $data): array
    {
        $pageId = $data['page_id'] ?? $this->client->pageId();

        if (! $pageId) {
            throw new MetaException('A Facebook Page ID is required to create an ad creative.');
        }

        $linkData = array_filter([
            'message'     => $data['message'] ?? null,
            'name'        => $data['headline'] ?? null,
            'description' => $data['description'] ?? null,
            'image_hash'  => $data['image_hash'] ?? null,
            'link'        => $data['link'] ?? 'https://facebook.com/' . $pageId,
        ], fn ($v) => $v !== null && $v !== '');

        // Destination decides the call-to-action payload.
        if (! empty($data['lead_form_id'])) {
            $linkData['call_to_action'] = [
                'type'  => $data['call_to_action'] ?? 'SIGN_UP',
                'value' => ['lead_gen_form_id' => $data['lead_form_id']],
            ];
        } elseif (! empty($data['whatsapp_number'])) {
            $linkData['link'] = 'https://api.whatsapp.com/send';
            $linkData['call_to_action'] = [
                'type'  => 'WHATSAPP_MESSAGE',
                'value' => ['app_destination' => 'WHATSAPP'],
            ];
        } elseif (! empty($data['call_to_action'])) {
            $linkData['call_to_action'] = [
                'type'  => $data['call_to_action'],
                'value' => ['link' => $linkData['link']],
            ];
        }

        $payload = [
            'name'              => $data['name'] ?? ($data['headline'] ?? 'Creative') . ' — ' . now()->format('d M Y'),
            'object_story_spec' => json_encode([
                'page_id'      => $pageId,
                'instagram_actor_id' => $data['instagram_actor_id'] ?? null,
                'link_data'    => $linkData,
            ]),
        ];

        if (! empty($data['degrees_of_freedom'])) {
            $payload['degrees_of_freedom_spec'] = json_encode($data['degrees_of_freedom']);
        }

        return $this->client->post($this->account() . '/adcreatives', $payload);
    }

    /**
     * Upload an image to the ad account's image library; returns its hash.
     */
    public function uploadAdImage(string $absolutePath): ?string
    {
        $response = $this->client->upload($this->account() . '/adimages', $absolutePath, 'filename');

        $images = $response['images'] ?? [];
        $first  = reset($images);

        return $first['hash'] ?? null;
    }

    /**
     * Rendered preview iframe for an ad, shown in the ad detail drawer.
     */
    public function adPreview(string $adExternalId, string $format = 'DESKTOP_FEED_STANDARD'): ?string
    {
        $response = $this->client->get($adExternalId . '/previews', ['ad_format' => $format]);

        return $response['data'][0]['body'] ?? null;
    }

    public function creativePreview(string $creativeId, string $format = 'DESKTOP_FEED_STANDARD'): ?string
    {
        $response = $this->client->get($this->account() . '/generatepreviews', [
            'creative'  => json_encode(['creative_id' => $creativeId]),
            'ad_format' => $format,
        ]);

        return $response['data'][0]['body'] ?? null;
    }

    // -----------------------------------------------------------------
    // Lead forms
    // -----------------------------------------------------------------

    public function listLeadForms(?string $pageId = null): array
    {
        $pageId = $pageId ?: $this->client->pageId();

        if (! $pageId) {
            throw new MetaException('A Facebook Page ID is required to list lead forms.');
        }

        return $this->client->paginate($pageId . '/leadgen_forms', [
            'fields' => 'id,name,status,locale,questions,privacy_policy_url,follow_up_action_url,leads_count,created_time',
        ], maxPages: 10, token: $this->pageToken($pageId));
    }

    public function getLeadForm(string $formId, ?string $pageId = null): array
    {
        return $this->client->get($formId, [
            'fields' => 'id,name,status,locale,questions,privacy_policy_url,follow_up_action_url,leads_count',
        ], $this->pageToken($pageId));
    }

    /**
     * Create a lead form on the Page. `questions` uses Meta's key format,
     * e.g. [['type' => 'FULL_NAME'], ['type' => 'PHONE'], ['type' => 'EMAIL']].
     */
    public function createLeadForm(array $data, ?string $pageId = null): array
    {
        $pageId = $pageId ?: $this->client->pageId();

        return $this->client->post($pageId . '/leadgen_forms', array_filter([
            'name'               => $data['name'],
            'locale'             => $data['locale'] ?? 'EN_US',
            'questions'          => json_encode($data['questions'] ?? [
                ['type' => 'FULL_NAME'],
                ['type' => 'PHONE'],
                ['type' => 'EMAIL'],
            ]),
            'privacy_policy'     => json_encode([
                'url'       => $data['privacy_policy_url'] ?? null,
                'link_text' => $data['privacy_policy_text'] ?? 'Privacy Policy',
            ]),
            'follow_up_action_url' => $data['follow_up_action_url'] ?? null,
            'context_card'       => ! empty($data['context_card']) ? json_encode($data['context_card']) : null,
            'thank_you_page'     => ! empty($data['thank_you_page']) ? json_encode($data['thank_you_page']) : null,
            'block_display_for_non_targeted_viewer' => $data['block_non_targeted'] ?? false,
        ], fn ($v) => $v !== null && $v !== ''), $this->pageToken($pageId));
    }

    /**
     * Every lead submitted through a form, newest first.
     */
    public function formLeads(string $formId, ?Carbon $since = null, ?string $pageId = null): array
    {
        $query = ['fields' => 'id,created_time,ad_id,adset_id,campaign_id,form_id,platform,is_organic,field_data'];

        if ($since) {
            $query['filtering'] = json_encode([[
                'field'    => 'time_created',
                'operator' => 'GREATER_THAN',
                'value'    => $since->timestamp,
            ]]);
        }

        return $this->client->paginate($formId . '/leads', $query, maxPages: 20, token: $this->pageToken($pageId));
    }

    public function getLead(string $leadgenId, ?string $pageId = null): array
    {
        return $this->client->get($leadgenId, [
            'fields' => 'id,created_time,ad_id,adset_id,campaign_id,form_id,platform,is_organic,field_data',
        ], $this->pageToken($pageId));
    }

    // -----------------------------------------------------------------
    // Page webhook subscription (Lead Ads)
    // -----------------------------------------------------------------

    /**
     * Meta only delivers `leadgen` webhooks for a Page once the app has been
     * subscribed to that Page with the leadgen field. This is separate from
     * the WABA subscription and from turning the field on in the App
     * Dashboard — without it, Lead Ads submissions never reach the CRM.
     */
    public function subscribePage(?string $pageId = null): array
    {
        $pageId = $pageId ?: $this->client->pageId();

        if (! $pageId) {
            throw new MetaException('A Facebook Page ID is required to subscribe to Lead Ads notifications.');
        }

        $token = $this->pageToken($pageId);

        if (! $token) {
            throw new MetaException('No Page access token is saved for this Page. Click "Discover" on the Ads tab first so the Page token is stored.');
        }

        return $this->client->post($pageId . '/subscribed_apps', [
            'subscribed_fields' => 'leadgen',
        ], $token);
    }

    /**
     * Apps subscribed to the Page, each with its `subscribed_fields`.
     */
    public function pageSubscriptions(?string $pageId = null): array
    {
        $pageId = $pageId ?: $this->client->pageId();

        if (! $pageId) {
            return [];
        }

        return $this->client->get($pageId . '/subscribed_apps', [], $this->pageToken($pageId))['data'] ?? [];
    }

    public function isPageSubscribedToLeadgen(?string $pageId = null): bool
    {
        foreach ($this->pageSubscriptions($pageId) as $app) {
            if (in_array('leadgen', $app['subscribed_fields'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function hasPageToken(?string $pageId = null): bool
    {
        return (bool) $this->pageToken($pageId);
    }

    /**
     * Reading leads requires a Page access token, not the user token. If one
     * was saved during setup, use it; otherwise fall back to the user token.
     */
    private function pageToken(?string $pageId = null): ?string
    {
        $tokens = $this->client->credential('page_tokens');

        if (! is_array($tokens)) {
            return null;
        }

        $pageId = $pageId ?: $this->client->pageId();

        return $tokens[$pageId] ?? null;
    }

    // -----------------------------------------------------------------
    // Insights
    // -----------------------------------------------------------------

    /**
     * Daily insights for one node and its children.
     *
     * @param  string  $level  account|campaign|adset|ad
     */
    /** Fields that identify the row at each level; without them a row cannot be attributed. */
    public const INSIGHT_ID_FIELDS = [
        'account'  => [],
        'campaign' => ['campaign_id', 'campaign_name'],
        'adset'    => ['campaign_id', 'adset_id', 'adset_name'],
        'ad'       => ['campaign_id', 'adset_id', 'ad_id', 'ad_name'],
    ];

    public function insights(string $objectId, string $level, Carbon $since, Carbon $until, bool $daily = true): array
    {
        $query = [
            'level'        => $level,
            'fields'       => implode(',', array_values(array_unique(array_merge(
                self::INSIGHT_ID_FIELDS[$level] ?? [],
                config('meta.ads.insight_fields'),
            )))),
            'time_range'   => json_encode([
                'since' => $since->toDateString(),
                'until' => $until->toDateString(),
            ]),
            'limit'        => 200,
        ];

        if ($daily) {
            $query['time_increment'] = 1;
        }

        return $this->client->paginate($objectId . '/insights', $query, maxPages: 20);
    }

    public function accountInsights(Carbon $since, Carbon $until, string $level = 'campaign', bool $daily = true): array
    {
        return $this->insights($this->account(), $level, $since, $until, $daily);
    }

    // -----------------------------------------------------------------
    // Targeting search (typeahead in the ad set builder)
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // Custom audiences
    // -----------------------------------------------------------------

    public const AUDIENCE_FIELDS = 'id,name,description,subtype,approximate_count_lower_bound,'
        . 'approximate_count_upper_bound,delivery_status,operation_status,time_updated,customer_file_source';

    /**
     * The schema keys Meta accepts for a hashed customer file, in the order
     * this CRM sends them. Everything except the country is patient data, so
     * every value is SHA-256 hashed before upload — Meta requires it and it
     * means no readable patient identity ever leaves the clinic.
     */
    public const AUDIENCE_SCHEMA = ['EMAIL', 'PHONE', 'FN', 'LN', 'CT', 'ST', 'ZIP', 'COUNTRY'];

    public function listCustomAudiences(int $maxPages = 5): array
    {
        return $this->client->paginate($this->account() . '/customaudiences', [
            'fields' => self::AUDIENCE_FIELDS,
        ], $maxPages);
    }

    public function getCustomAudience(string $audienceId): array
    {
        return $this->client->get($audienceId, ['fields' => self::AUDIENCE_FIELDS]);
    }

    /**
     * Create an empty customer-file audience, ready to be filled.
     *
     * `customer_file_source` is a legal declaration about where the data came
     * from, not a formality — a clinic uploading its own patient list must say
     * so, which is why it defaults to USER_PROVIDED_ONLY here.
     */
    public function createCustomAudience(array $data): array
    {
        return $this->client->post($this->account() . '/customaudiences', array_filter([
            'name'                 => $data['name'],
            'description'          => $data['description'] ?? null,
            'subtype'              => $data['subtype'] ?? 'CUSTOM',
            'customer_file_source' => $data['customer_file_source'] ?? 'USER_PROVIDED_ONLY',
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public function deleteCustomAudience(string $audienceId): array
    {
        return $this->client->delete($audienceId);
    }

    /**
     * Add hashed people to an audience.
     *
     * @param  array<int,array<int,string|null>>  $rows  Rows already ordered to
     *         match AUDIENCE_SCHEMA. Nulls are allowed; Meta treats a blank
     *         cell as "no value for this key" rather than a failed match.
     * @param  string|null  $sessionId  Groups a multi-batch upload so Meta can
     *         report the result of the whole file rather than each chunk.
     */
    public function addUsersToAudience(string $audienceId, array $rows, ?string $sessionId = null, int $batchSeq = 1, bool $lastBatch = true): array
    {
        return $this->client->post($audienceId . '/users', array_filter([
            'payload' => json_encode([
                'schema' => self::AUDIENCE_SCHEMA,
                'data'   => $rows,
            ]),
            'session' => $sessionId ? json_encode([
                'session_id'     => $sessionId,
                'batch_seq'      => $batchSeq,
                'last_batch_flag'=> $lastBatch,
            ]) : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Remove people from an audience — used to drop couples who have since
     * become patients out of a prospecting audience.
     */
    public function removeUsersFromAudience(string $audienceId, array $rows): array
    {
        return $this->client->delete($audienceId . '/users', [
            'payload' => json_encode([
                'schema' => self::AUDIENCE_SCHEMA,
                'data'   => $rows,
            ]),
        ]);
    }

    /**
     * A lookalike of an existing audience.
     *
     * `ratio` is how far to widen the net: 0.01 is the closest 1% of the
     * country's population and converts best; 0.10 is the widest and cheapest.
     */
    public function createLookalikeAudience(array $data): array
    {
        return $this->client->post($this->account() . '/customaudiences', [
            'name'          => $data['name'],
            'subtype'       => 'LOOKALIKE',
            'origin_audience_id' => $data['origin_audience_id'],
            'lookalike_spec' => json_encode([
                'ratio'     => (float) ($data['ratio'] ?? 0.01),
                'country'   => $data['country'] ?? 'IN',
                'type'      => $data['type'] ?? 'custom_ratio',
            ]),
        ]);
    }

    /**
     * Hash one person into a row matching AUDIENCE_SCHEMA.
     *
     * Normalisation matters more than it looks: Meta hashes its own side the
     * same way, so a stray capital letter or a "+91" is the difference between
     * a match and a miss.
     *
     * @return array<int,string>
     */
    public function hashAudienceRow(array $person): array
    {
        $phone = preg_replace('/\D/', '', (string) ($person['phone'] ?? ''));

        if ($phone !== '' && strlen($phone) === 10) {
            $phone = '91' . $phone;
        }

        $values = [
            'EMAIL'   => strtolower(trim((string) ($person['email'] ?? ''))),
            'PHONE'   => $phone,
            'FN'      => strtolower(trim((string) ($person['first_name'] ?? ''))),
            'LN'      => strtolower(trim((string) ($person['last_name'] ?? ''))),
            'CT'      => preg_replace('/[^a-z]/', '', strtolower((string) ($person['city'] ?? ''))),
            'ST'      => preg_replace('/[^a-z]/', '', strtolower((string) ($person['state'] ?? ''))),
            'ZIP'     => strtolower(trim((string) ($person['zip'] ?? ''))),
            'COUNTRY' => strtolower(trim((string) ($person['country'] ?? 'in'))),
        ];

        // A blank cell must stay blank, not become the hash of an empty string —
        // that would be a value every empty row matched on.
        return array_map(
            fn ($value) => $value === '' ? '' : hash('sha256', $value),
            array_values($values),
        );
    }

    public function searchInterests(string $query, int $limit = 25): array
    {
        return $this->client->get('search', [
            'type'  => 'adinterest',
            'q'     => $query,
            'limit' => $limit,
        ])['data'] ?? [];
    }

    /**
     * @param  string  $type  city|region|country|zip
     */
    public function searchLocations(string $query, string $type = 'city', int $limit = 25): array
    {
        return $this->client->get('search', [
            'type'          => 'adgeolocation',
            'location_types' => json_encode([$type]),
            'q'             => $query,
            'limit'         => $limit,
        ])['data'] ?? [];
    }

    /**
     * Estimated reach for a targeting spec, shown live in the builder.
     */
    public function reachEstimate(array $targeting, string $optimizationGoal = 'LEAD_GENERATION'): array
    {
        return $this->client->get($this->account() . '/delivery_estimate', [
            'optimization_goal' => $optimizationGoal,
            'targeting_spec'    => json_encode($this->buildTargeting($targeting)),
        ])['data'][0] ?? [];
    }
}
