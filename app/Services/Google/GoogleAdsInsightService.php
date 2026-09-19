<?php

namespace App\Services\Google;

use App\Models\ChannelSpend;
use App\Support\AcquisitionChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pulls daily Google Ads spend into the CRM.
 *
 * The counterpart to the Meta insights sync. Without it the funnel can say
 * Google produced forty enquiries and Meta produced sixty, but not which of
 * them cost less per couple who actually started a cycle — and that is the only
 * number that ever moves a budget.
 *
 * Spend is stored per campaign per day so a re-sync of an overlapping window
 * updates rather than double counts. Google restates the last few days as
 * invalid clicks are filtered out, which is why the sync window overlaps by
 * default rather than only fetching yesterday.
 */
class GoogleAdsInsightService
{
    public function __construct(private GoogleAdsClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Sync a date range. Defaults to the last 30 days, which comfortably covers
     * Google's restatement window.
     *
     * @return array{rows:int, spend:float, from:string, to:string}
     */
    public function sync(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?: now()->subDays(30);
        $to   = $to   ?: now();

        if (! $this->isConfigured()) {
            throw new GoogleAdsException('Google Ads is not connected. Add the credentials under Settings → Integrations → Google Ads.');
        }

        $gaql = sprintf(
            'SELECT campaign.id, campaign.name, segments.date, metrics.cost_micros, '
            . 'metrics.impressions, metrics.clicks, metrics.conversions, customer.currency_code '
            . 'FROM campaign WHERE segments.date BETWEEN "%s" AND "%s"',
            $from->toDateString(),
            $to->toDateString(),
        );

        $rows  = $this->client->search($gaql);
        $count = 0;
        $spend = 0.0;

        foreach ($rows as $row) {
            $record = $this->toRecord($row);

            if (! $record) {
                continue;
            }

            // A hand-entered figure represents money the platform cannot see,
            // so a sync must never overwrite it.
            $existing = ChannelSpend::where('channel', AcquisitionChannel::GOOGLE_ADS)
                ->where('date', $record['date'])
                ->where('external_campaign_id', $record['external_campaign_id'])
                ->first();

            if ($existing?->is_manual) {
                continue;
            }

            ChannelSpend::updateOrCreate(
                [
                    'channel'              => AcquisitionChannel::GOOGLE_ADS,
                    'date'                 => $record['date'],
                    'external_campaign_id' => $record['external_campaign_id'],
                ],
                $record + ['synced_at' => now(), 'is_manual' => false],
            );

            $count++;
            $spend += $record['spend'];
        }

        Log::info('Google Ads spend synced', [
            'rows'  => $count,
            'spend' => round($spend, 2),
            'from'  => $from->toDateString(),
            'to'    => $to->toDateString(),
        ]);

        return [
            'rows'  => $count,
            'spend' => round($spend, 2),
            'from'  => $from->toDateString(),
            'to'    => $to->toDateString(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function toRecord(array $row): ?array
    {
        $campaign = $row['campaign'] ?? [];
        $metrics  = $row['metrics'] ?? [];
        $date     = $row['segments']['date'] ?? null;

        if (! $date || empty($campaign['id'])) {
            return null;
        }

        return [
            'date'                   => $date,
            'external_campaign_id'   => (string) $campaign['id'],
            'external_campaign_name' => $campaign['name'] ?? null,

            // Google reports cost in micros — a millionth of the account
            // currency — not in the currency itself.
            'spend'       => round(((float) ($metrics['costMicros'] ?? 0)) / 1_000_000, 2),
            'impressions' => (int) ($metrics['impressions'] ?? 0),
            'clicks'      => (int) ($metrics['clicks'] ?? 0),
            'conversions' => round((float) ($metrics['conversions'] ?? 0), 2),
            'currency'    => $row['customer']['currencyCode'] ?? $this->client->credential('currency', 'INR'),
        ];
    }

    /**
     * Campaign-level totals for the window, for the analytics table.
     *
     * @return array<int,array<string,mixed>>
     */
    public function campaignTotals(Carbon $from, Carbon $to): array
    {
        return ChannelSpend::query()
            ->where('channel', AcquisitionChannel::GOOGLE_ADS)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('external_campaign_id, MAX(external_campaign_name) as name')
            ->selectRaw('SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(conversions) as conversions')
            ->groupBy('external_campaign_id')
            ->orderByDesc('spend')
            ->get()
            ->map(fn ($row) => [
                'external_id' => $row->external_campaign_id,
                'name'        => $row->name,
                'spend'       => round((float) $row->spend, 2),
                'impressions' => (int) $row->impressions,
                'clicks'      => (int) $row->clicks,
                'conversions' => round((float) $row->conversions, 2),
            ])
            ->all();
    }
}
