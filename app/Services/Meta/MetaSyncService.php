<?php

namespace App\Services\Meta;

use App\Models\Ad;
use App\Models\Adset;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\MetaInsight;
use App\Models\MetaLeadForm;
use App\Models\WhatsappTemplate;
use App\Services\LeadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Two-way reconciliation between Meta and the CRM's own tables.
 *
 * Everything keys off `external_id`, so a sync can be re-run safely: it will
 * update in place rather than duplicate. Local-only campaigns (external_id is
 * null) are left untouched.
 */
class MetaSyncService
{
    public function __construct(
        private MetaAdsService $ads,
        private WhatsAppService $whatsapp,
        private MetaClient $client,
        private LeadService $leads,
    ) {}

    // -----------------------------------------------------------------
    // Ad structure
    // -----------------------------------------------------------------

    /**
     * Pull campaigns, ad sets and ads from the connected ad account.
     *
     * @return array{campaigns:int, adsets:int, ads:int}
     */
    public function syncStructure(): array
    {
        $source  = $this->metaLeadSource();
        $account = $this->client->adAccountId();

        $counts = ['campaigns' => 0, 'adsets' => 0, 'ads' => 0];

        $campaignMap = [];
        foreach ($this->ads->listCampaigns() as $remote) {
            $campaign = Campaign::updateOrCreate(
                ['external_id' => $remote['id']],
                [
                    'name'                => $remote['name'] ?? 'Untitled campaign',
                    'platform'            => 'meta',
                    'external_account_id' => $account,
                    'objective'           => $remote['objective'] ?? null,
                    'status'              => $remote['status'] ?? 'PAUSED',
                    'effective_status'    => $remote['effective_status'] ?? null,
                    'buying_type'         => $remote['buying_type'] ?? null,
                    'daily_budget'        => $this->ads->fromMinor($remote['daily_budget'] ?? null),
                    'lifetime_budget'     => $this->ads->fromMinor($remote['lifetime_budget'] ?? null),
                    'bid_strategy'        => $remote['bid_strategy'] ?? null,
                    'special_ad_categories' => $remote['special_ad_categories'] ?? [],
                    'start_date'          => isset($remote['start_time']) ? Carbon::parse($remote['start_time'])->toDateString() : null,
                    'end_date'            => isset($remote['stop_time']) ? Carbon::parse($remote['stop_time'])->toDateString() : null,
                    'is_active'           => ($remote['status'] ?? '') === 'ACTIVE',
                    'lead_source_id'      => $source?->id,
                    'synced_at'           => now(),
                    'sync_error'          => null,
                ],
            );

            $campaignMap[$remote['id']] = $campaign->id;
            $counts['campaigns']++;
        }

        $adsetMap = [];
        foreach ($this->ads->listAdsets() as $remote) {
            $campaignId = $campaignMap[$remote['campaign_id'] ?? ''] ?? null;

            if (! $campaignId) {
                continue;
            }

            $adset = Adset::updateOrCreate(
                ['external_id' => $remote['id']],
                [
                    'campaign_id'       => $campaignId,
                    'name'              => $remote['name'] ?? 'Untitled ad set',
                    'status'            => $remote['status'] ?? 'PAUSED',
                    'effective_status'  => $remote['effective_status'] ?? null,
                    'daily_budget'      => $this->ads->fromMinor($remote['daily_budget'] ?? null),
                    'lifetime_budget'   => $this->ads->fromMinor($remote['lifetime_budget'] ?? null),
                    'optimization_goal' => $remote['optimization_goal'] ?? null,
                    'billing_event'     => $remote['billing_event'] ?? null,
                    'bid_amount'        => $this->ads->fromMinor($remote['bid_amount'] ?? null),
                    'targeting'         => $remote['targeting'] ?? null,
                    'promoted_object'   => $remote['promoted_object'] ?? null,
                    'destination_type'  => $remote['destination_type'] ?? null,
                    'start_time'        => isset($remote['start_time']) ? Carbon::parse($remote['start_time']) : null,
                    'end_time'          => isset($remote['end_time']) ? Carbon::parse($remote['end_time']) : null,
                    'synced_at'         => now(),
                    'sync_error'        => null,
                ],
            );

            $adsetMap[$remote['id']] = $adset->id;
            $counts['adsets']++;
        }

        foreach ($this->ads->listAds() as $remote) {
            $adsetId = $adsetMap[$remote['adset_id'] ?? ''] ?? null;

            if (! $adsetId) {
                continue;
            }

            $creative = $remote['creative'] ?? [];

            Ad::updateOrCreate(
                ['external_id' => $remote['id']],
                [
                    'adset_id'             => $adsetId,
                    'name'                 => $remote['name'] ?? 'Untitled ad',
                    'status'               => $remote['status'] ?? 'PAUSED',
                    'effective_status'     => $remote['effective_status'] ?? null,
                    'external_creative_id' => $creative['id'] ?? null,
                    'creative'             => $creative ?: null,
                    'thumbnail_url'        => $creative['thumbnail_url'] ?? $creative['image_url'] ?? null,
                    'synced_at'            => now(),
                    'sync_error'           => null,
                ],
            );

            $counts['ads']++;
        }

        return $counts;
    }

    // -----------------------------------------------------------------
    // Insights
    // -----------------------------------------------------------------

    /**
     * Pull daily insights for every level and upsert into meta_insights.
     */
    public function syncInsights(?Carbon $since = null, ?Carbon $until = null): int
    {
        $since = $since ?: now()->subDays(30);
        $until = $until ?: now();

        $written = 0;

        // One request per level and per week: a month of daily rows for the
        // whole account is several hundred KB and used to time out, which
        // aborted the entire sync and left every spend figure at zero.
        $windows = [];
        for ($start = $since->copy()->startOfDay(); $start->lte($until); $start = $start->copy()->addDays(7)) {
            $windows[] = [$start->copy(), $start->copy()->addDays(6)->min($until)];
        }

        foreach (['campaign', 'adset', 'ad'] as $level) {
            $rows = [];

            foreach ($windows as [$from, $to]) {
                try {
                    $rows = array_merge($rows, $this->ads->accountInsights($from, $to, $level));
                } catch (\Throwable $e) {
                    Log::warning("Meta insights sync failed at {$level} level", [
                        'window' => $from->toDateString() . '..' . $to->toDateString(),
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            foreach ($rows as $row) {
                $externalId = $row["{$level}_id"] ?? null;

                if (! $externalId || empty($row['date_start'])) {
                    continue;
                }

                $actions = $row['actions'] ?? [];
                $leads   = MetaInsight::leadCount($actions);
                $spend   = (float) ($row['spend'] ?? 0);

                MetaInsight::updateOrCreate(
                    [
                        'level'       => $level,
                        'external_id' => $externalId,
                        'date'        => $row['date_start'],
                    ],
                    [
                        'entity_id'      => $this->localId($level, $externalId),
                        'impressions'    => (int) ($row['impressions'] ?? 0),
                        'reach'          => (int) ($row['reach'] ?? 0),
                        'clicks'         => (int) ($row['clicks'] ?? 0),
                        'unique_clicks'  => (int) ($row['unique_clicks'] ?? 0),
                        'spend'          => $spend,
                        'frequency'      => (float) ($row['frequency'] ?? 0),
                        'ctr'            => (float) ($row['ctr'] ?? 0),
                        'cpc'            => (float) ($row['cpc'] ?? 0),
                        'cpm'            => (float) ($row['cpm'] ?? 0),
                        'leads'          => $leads,
                        'cost_per_lead'  => $leads > 0 ? round($spend / $leads, 4) : 0,
                        'messaging_conversations' => (int) MetaInsight::actionValue($actions, 'onsite_conversion.messaging_conversation_started_7d'),
                        'landing_page_views'      => (int) MetaInsight::actionValue($actions, 'landing_page_view'),
                        'actions'        => $actions ?: null,
                    ],
                );

                $written++;
            }
        }

        $this->rollUpSpend();

        return $written;
    }

    private function localId(string $level, string $externalId): ?int
    {
        return match ($level) {
            'campaign' => Campaign::where('external_id', $externalId)->value('id'),
            'adset'    => Adset::where('external_id', $externalId)->value('id'),
            'ad'       => Ad::where('external_id', $externalId)->value('id'),
            default    => null,
        };
    }

    /**
     * Denormalise lifetime spend back onto campaigns/adsets/ads so existing
     * CPL reports keep working without joining the insights table.
     */
    private function rollUpSpend(): void
    {
        foreach ([['campaign', Campaign::class], ['adset', Adset::class], ['ad', Ad::class]] as [$level, $model]) {
            $totals = MetaInsight::where('level', $level)
                ->whereNotNull('entity_id')
                ->selectRaw('entity_id, SUM(spend) as total')
                ->groupBy('entity_id')
                ->pluck('total', 'entity_id');

            foreach ($totals as $id => $total) {
                $model::whereKey($id)->update(['spend' => $total]);
            }
        }
    }

    // -----------------------------------------------------------------
    // Lead forms + leads
    // -----------------------------------------------------------------

    public function syncLeadForms(?string $pageId = null): int
    {
        $count = 0;

        foreach ($this->ads->listLeadForms($pageId) as $remote) {
            MetaLeadForm::updateOrCreate(
                ['external_id' => $remote['id']],
                [
                    'page_id'              => $pageId ?: $this->client->pageId(),
                    'name'                 => $remote['name'] ?? 'Untitled form',
                    'status'               => $remote['status'] ?? 'ACTIVE',
                    'locale'               => $remote['locale'] ?? null,
                    'questions'            => $remote['questions'] ?? null,
                    'privacy_policy_url'   => $remote['privacy_policy_url'] ?? null,
                    'follow_up_action_url' => $remote['follow_up_action_url'] ?? null,
                    'leads_count'          => (int) ($remote['leads_count'] ?? 0),
                    'last_synced_at'       => now(),
                ],
            );

            $count++;
        }

        return $count;
    }

    /**
     * Backfill leads for a form (the webhook covers new ones going forward).
     */
    public function syncFormLeads(MetaLeadForm $form, ?Carbon $since = null): int
    {
        // A form that has never had its leads pulled starts with a long
        // backfill; afterwards the pull continues from its own watermark
        // (never from last_synced_at, which the hourly metadata sync moves).
        $since = $since ?: ($form->leads_synced_at
            ? $form->leads_synced_at->copy()->subMinutes(30)
            : now()->subDays((int) config('meta.leads.backfill_days', 90)));

        $created = 0;

        foreach ($this->ads->formLeads($form->external_id, $since, $form->page_id) as $remote) {
            if ($this->importLead($remote, $form)) {
                $created++;
            }
        }

        $form->update(['leads_synced_at' => now()]);

        return $created;
    }

    /**
     * Convert one Meta lead payload into a CRM lead.
     *
     * @return bool True when a new lead row was created.
     */
    public function importLead(array $remote, ?MetaLeadForm $form = null, array $context = []): bool
    {
        $externalId = $remote['id'] ?? null;

        if (! $externalId) {
            return false;
        }

        if (Lead::where('external_lead_id', $externalId)->exists()) {
            return false;
        }

        $form = $form ?: MetaLeadForm::where('external_id', $remote['form_id'] ?? '')->first();

        $answers = [];
        foreach ($remote['field_data'] ?? [] as $field) {
            $answers[$field['name'] ?? ''] = $field['values'][0] ?? null;
        }

        $map    = $form?->effectiveFieldMap() ?? MetaLeadForm::DEFAULT_FIELD_MAP;
        $mapped = [];

        foreach ($answers as $key => $value) {
            $target = $map[$key] ?? MetaLeadForm::guessTarget((string) $key);
            if ($target && $value !== null && $value !== '') {
                // A form may ask both first_name and full_name; keep the first hit.
                $mapped[$target] = $mapped[$target] ?? $value;
            }
        }

        // "How many years have you been trying?" style answers become dates
        // so the number keeps counting, exactly as on the lead form.
        $coupleDates = [];
        foreach (['years_trying' => 'trying_since', 'years_married' => 'marriage_date', 'wife_age' => 'wife_dob', 'husband_age' => 'husband_dob'] as $yearsKey => $dateKey) {
            if (isset($mapped[$yearsKey]) && preg_match('/\d{1,3}/', (string) $mapped[$yearsKey], $m)) {
                $coupleDates[$dateKey] = \App\Support\CoupleDates::dateFromYears((int) $m[0])?->toDateString();
            }
        }

        $attribution = $this->resolveAttribution(
            $remote['campaign_id'] ?? ($context['campaign_id'] ?? null),
            $remote['adset_id'] ?? ($context['adgroup_id'] ?? null),
            $remote['ad_id'] ?? ($context['ad_id'] ?? null),
        );

        // Leads are captured per campaign: the campaign decides the centre,
        // the agent and the source. The form only maps question keys, and is
        // consulted only when the campaign has nothing to say.
        $campaign = isset($attribution['campaign_id'])
            ? Campaign::find($attribution['campaign_id'])
            : null;

        $payload = array_filter([
            'name'             => $mapped['name'] ?? null,
            'mobile'           => isset($mapped['mobile']) ? $this->whatsapp->normalizeNumber($mapped['mobile']) : null,
            'email'            => $mapped['email'] ?? null,
            'gender'           => $this->normalizeGender($mapped['gender'] ?? null),
            'pincode'          => $mapped['pincode'] ?? null,
            'external_lead_id' => $externalId,
            'lead_source_id'   => $campaign?->lead_source_id ?: ($form?->lead_source_id ?: $this->metaLeadSource()?->id),
            'center_id'        => $campaign?->center_id ?: $form?->center_id,
            'assigned_to'      => $campaign?->assign_to ?: $form?->assign_to,
            'utm_source'       => 'facebook',
            'utm_medium'       => 'paid_social',
            'lead_date'        => isset($remote['created_time']) ? Carbon::parse($remote['created_time']) : now(),
            'remark'           => $mapped['message'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $payload = array_merge($payload, $attribution, array_filter($coupleDates));

        $payload['extra'] = [
            'meta_lead_id'  => $externalId,
            'form_id'       => $remote['form_id'] ?? $form?->external_id,
            'form_name'     => $form?->name,
            'platform'      => $remote['platform'] ?? 'facebook',
            'is_organic'    => $remote['is_organic'] ?? false,
            'raw_answers'   => $answers,
        ];

        $payload['source_name'] = 'Meta Lead Ad';

        try {
            $this->leads->create($payload);
            $form?->increment('leads_count');

            return true;
        } catch (\Throwable $e) {
            Log::error('Meta lead import failed', [
                'leadgen_id' => $externalId,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Attach the lead to the local campaign/adset/ad rows when they exist.
     */
    private function resolveAttribution(?string $campaignId, ?string $adsetId, ?string $adId): array
    {
        $out = [];

        if ($campaignId && $id = ($this->localCampaignId($campaignId))) {
            $out['campaign_id'] = $id;
            $out['utm_campaign'] = Campaign::whereKey($id)->value('name');
        }
        if ($adsetId && $id = Adset::where('external_id', $adsetId)->value('id')) {
            $out['adset_id'] = $id;
        }
        if ($adId && $id = Ad::where('external_id', $adId)->value('id')) {
            $out['ad_id'] = $id;
            $out['utm_content'] = Ad::whereKey($id)->value('name');
        }

        return $out;
    }

    /**
     * A lead can arrive before the hourly structure sync has mirrored a brand
     * new campaign; fetch it on demand so the lead is still captured under
     * its campaign rather than falling through to form-level defaults.
     */
    private function localCampaignId(string $externalId): ?int
    {
        if ($id = Campaign::where('external_id', $externalId)->value('id')) {
            return (int) $id;
        }

        try {
            $remote = $this->ads->getCampaign($externalId);
        } catch (\Throwable $e) {
            Log::info('Meta campaign not mirrored yet; lead captured without campaign', [
                'campaign_id' => $externalId,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }

        if (empty($remote['id'])) {
            return null;
        }

        $campaign = Campaign::firstOrCreate(
            ['external_id' => $remote['id']],
            [
                'name'                => $remote['name'] ?? 'Untitled campaign',
                'platform'            => 'meta',
                'external_account_id' => $this->client->adAccountId(),
                'objective'           => $remote['objective'] ?? null,
                'status'              => $remote['status'] ?? 'PAUSED',
                'effective_status'    => $remote['effective_status'] ?? null,
                'is_active'           => ($remote['status'] ?? '') === 'ACTIVE',
                'lead_source_id'      => $this->metaLeadSource()?->id,
                'synced_at'           => now(),
            ],
        );

        return $campaign->id;
    }

    private function normalizeGender(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'male', 'm', 'man'     => 'male',
            'female', 'f', 'woman' => 'female',
            default                => 'other',
        };
    }

    private function metaLeadSource(): ?LeadSource
    {
        return LeadSource::where('slug', 'meta')
            ->orWhere('channel', 'meta')
            ->orderByRaw("slug = 'meta' desc")
            ->first();
    }

    // -----------------------------------------------------------------
    // WhatsApp templates
    // -----------------------------------------------------------------

    public function syncTemplates(?\App\Models\ChannelAccount $account = null): int
    {
        $count = 0;

        foreach ($this->whatsapp->listTemplates($account) as $remote) {
            $components = $remote['components'] ?? [];
            $extracted  = WhatsappTemplate::extractFromComponents($components);

            WhatsappTemplate::updateOrCreate(
                [
                    'channel_account_id' => $account?->id,
                    'name'               => $remote['name'],
                    'language'           => $remote['language'] ?? 'en',
                ],
                [
                    'category'        => $remote['category'] ?? 'UTILITY',
                    'status'          => $remote['status'] ?? 'PENDING',
                    'external_id'     => $remote['id'] ?? null,
                    'components'      => $components,
                    'body_text'       => $extracted['body_text'],
                    'variable_count'  => $extracted['variable_count'],
                    'header_format'   => $extracted['header_format'],
                    'rejected_reason' => $remote['rejected_reason'] ?? null,
                    'synced_at'       => now(),
                ],
            );

            $count++;
        }

        return $count;
    }
}
