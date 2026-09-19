<?php

namespace App\Services\Marketing;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\MetaInsight;
use App\Models\PipelineStage;
use App\Support\AcquisitionChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Lead → Contacted → Interested → Booked → Visited → Treatment → Won funnel,
 * costed against Meta ad spend.
 *
 * Stage membership is cumulative: a lead that has visited also counts as booked
 * and contacted, so the funnel only ever narrows. That is what makes the
 * step-to-step conversion rates meaningful.
 */
class FunnelService
{
    /** Ordered funnel definition: key => [label, icon]. */
    public const STEPS = [
        'leads'      => ['Leads',              'bi-people'],
        'contacted'  => ['Contacted',          'bi-telephone'],
        'interested' => ['Interested',         'bi-hand-thumbs-up'],
        'booked'     => ['Consultation Booked','bi-calendar-check'],
        'visited'    => ['Visited',            'bi-door-open'],
        'treatment'  => ['Treatment Started',  'bi-clipboard2-pulse'],
        'won'        => ['Converted',          'bi-trophy'],
    ];

    /**
     * @param  array  $filters  from, to, center_id, campaign_id, lead_source_id, assigned_to
     * @return array{steps:array, totals:array, cost:array}
     */
    public function build(array $filters = []): array
    {
        [$from, $to] = $this->range($filters);

        $base = fn () => $this->baseQuery($filters, $from, $to);

        $counts = [
            'leads'      => (clone $base())->count(),
            'contacted'  => (clone $base())->where(fn (Builder $q) =>
                                $q->whereNotNull('first_contacted_at')->orWhere('contact_attempts', '>', 0)
                            )->count(),
            'interested' => (clone $base())->whereIn('stage_id', $this->stageIdsFrom('interested'))->count(),
            'booked'     => (clone $base())->where(fn (Builder $q) =>
                                $q->whereIn('stage_id', $this->stageIdsFrom('consultation-booked'))
                                  ->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                                      ->from('appointments')
                                      ->whereColumn('appointments.lead_id', 'leads.id')
                                      ->whereNull('appointments.deleted_at'))
                            )->count(),
            'visited'    => (clone $base())->where(fn (Builder $q) =>
                                $q->whereIn('stage_id', $this->stageIdsFrom('consultation-visited'))
                                  ->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                                      ->from('appointments')
                                      ->whereColumn('appointments.lead_id', 'leads.id')
                                      ->where('appointments.status', 'visited')
                                      ->whereNull('appointments.deleted_at'))
                            )->count(),
            'treatment'  => (clone $base())->whereIn('stage_id', $this->stageIdsFrom('investigation'))->count(),
            'won'        => (clone $base())->where('status', 'won')->count(),
        ];

        $steps = [];
        $previous = null;
        $top = max($counts['leads'], 1);

        foreach (self::STEPS as $key => [$label, $icon]) {
            $value = $counts[$key];

            $steps[] = [
                'key'          => $key,
                'label'        => $label,
                'icon'         => $icon,
                'count'        => $value,
                // Share of the very top of the funnel.
                'overall_rate' => round(($value / $top) * 100, 1),
                // Share of the immediately preceding step.
                'step_rate'    => $previous === null ? 100.0 : ($previous > 0 ? round(($value / $previous) * 100, 1) : 0.0),
                'dropped'      => $previous === null ? 0 : max($previous - $value, 0),
            ];

            $previous = $value;
        }

        return [
            'steps'  => $steps,
            'totals' => $counts,
            'cost'   => $this->costMetrics($counts, $filters, $from, $to),
            'range'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ];
    }

    /**
     * Cost efficiency of the funnel against Meta spend for the same window.
     */
    private function costMetrics(array $counts, array $filters, Carbon $from, Carbon $to): array
    {
        $spend = $this->spend($filters, $from, $to);

        $per = fn (int $count) => $count > 0 ? round($spend / $count, 2) : null;

        return [
            'spend'             => round($spend, 2),
            'cost_per_lead'     => $per($counts['leads']),
            'cost_per_booking'  => $per($counts['booked']),
            'cost_per_visit'    => $per($counts['visited']),
            'cost_per_conversion' => $per($counts['won']),
            'lead_to_visit'     => $counts['leads'] > 0 ? round(($counts['visited'] / $counts['leads']) * 100, 1) : 0.0,
            'visit_to_won'      => $counts['visited'] > 0 ? round(($counts['won'] / $counts['visited']) * 100, 1) : 0.0,
        ];
    }

    private function spend(array $filters, Carbon $from, Carbon $to): float
    {
        $query = MetaInsight::where('level', 'campaign')->whereBetween('date', [$from, $to]);

        if (! empty($filters['campaign_id'])) {
            $query->where('entity_id', $filters['campaign_id']);
        } elseif (! empty($filters['center_id'])) {
            $ids = Campaign::where('center_id', $filters['center_id'])->pluck('id');
            $query->whereIn('entity_id', $ids);
        }

        return (float) $query->sum('spend');
    }

    /**
     * Day-by-day series for the trend chart: new leads vs bookings vs visits.
     */
    public function trend(array $filters = []): array
    {
        [$from, $to] = $this->range($filters);

        $leads = $this->baseQuery($filters, $from, $to)
            ->selectRaw('DATE(leads.created_at) as d, COUNT(*) as c')
            ->groupBy('d')->pluck('c', 'd');

        $appointments = Appointment::query()
            ->whereBetween('scheduled_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(! empty($filters['center_id']), fn ($q) => $q->where('center_id', $filters['center_id']))
            ->selectRaw('DATE(scheduled_at) as d, COUNT(*) as booked, SUM(status = "visited") as visited')
            ->groupBy('d')->get()->keyBy('d');

        $spend = MetaInsight::where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->when(! empty($filters['campaign_id']), fn ($q) => $q->where('entity_id', $filters['campaign_id']))
            ->selectRaw('date as d, SUM(spend) as s')
            ->groupBy('d')->pluck('s', 'd');

        $labels = [];
        $series = ['leads' => [], 'booked' => [], 'visited' => [], 'spend' => []];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('d M');
            $series['leads'][]   = (int) ($leads[$key] ?? 0);
            $series['booked'][]  = (int) ($appointments[$key]->booked ?? 0);
            $series['visited'][] = (int) ($appointments[$key]->visited ?? 0);
            $series['spend'][]   = round((float) ($spend[$key] ?? 0), 2);
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Per-campaign funnel breakdown — the table under the funnel chart.
     */
    public function byCampaign(array $filters = []): Collection
    {
        [$from, $to] = $this->range($filters);

        $spend = MetaInsight::where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->selectRaw('entity_id, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('entity_id')
            ->get()
            ->keyBy('entity_id');

        return Campaign::query()
            ->when(! empty($filters['center_id']), fn ($q) => $q->where('center_id', $filters['center_id']))
            ->withCount([
                'leads as leads_count' => fn ($q) => $q->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]),
                'leads as booked_count' => fn ($q) => $q
                    ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                    ->whereIn('stage_id', $this->stageIdsFrom('consultation-booked')),
                'leads as visited_count' => fn ($q) => $q
                    ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                    ->whereIn('stage_id', $this->stageIdsFrom('consultation-visited')),
                'leads as won_count' => fn ($q) => $q
                    ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                    ->where('status', 'won'),
            ])
            ->having('leads_count', '>', 0)
            ->orderByDesc('leads_count')
            ->get()
            ->map(function (Campaign $campaign) use ($spend) {
                $row = $spend[$campaign->id] ?? null;
                $campaignSpend = (float) ($row->spend ?? 0);

                $campaign->period_spend       = $campaignSpend;
                $campaign->period_impressions = (int) ($row->impressions ?? 0);
                $campaign->period_clicks      = (int) ($row->clicks ?? 0);
                $campaign->cpl                = $campaign->leads_count ? round($campaignSpend / $campaign->leads_count, 2) : null;
                $campaign->cost_per_visit     = $campaign->visited_count ? round($campaignSpend / $campaign->visited_count, 2) : null;
                $campaign->cost_per_won       = $campaign->won_count ? round($campaignSpend / $campaign->won_count, 2) : null;
                $campaign->lead_to_visit      = $campaign->leads_count ? round(($campaign->visited_count / $campaign->leads_count) * 100, 1) : 0;

                return $campaign;
            });
    }

    /**
     * The same funnel, split by the channel that paid for the click.
     *
     * This is the question the clinic actually asks every month: Google or
     * Meta? It is only answerable because a lead and a patient are one record —
     * the treatment column below is the clinical outcome of a marketing click.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function byChannel(array $filters = []): Collection
    {
        [$from, $to] = $this->range($filters);

        $window = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        // One grouped query rather than a funnel query per channel: at lakhs of
        // rows the difference is a page that loads versus one that times out.
        $rows = $this->baseQuery($filters, $from, $to)
            ->leftJoin('patients', 'patients.id', '=', 'leads.patient_id')
            ->selectRaw('COALESCE(leads.channel, "manual") as channel')
            ->selectRaw('COUNT(*) as leads_count')
            ->selectRaw('SUM(leads.first_contacted_at IS NOT NULL OR leads.contact_attempts > 0) as contacted_count')
            ->selectRaw('SUM(patients.journey_stage IN ("consultation", "treatment", "closed")) as consultation_count')
            ->selectRaw('SUM(patients.journey_stage IN ("treatment", "closed")) as treatment_count')
            ->selectRaw('SUM(leads.status = "won") as won_count')
            ->selectRaw('SUM(leads.status IN ("lost", "junk")) as lost_count')
            ->groupBy('channel')
            ->get();

        // Spend is only known for channels the CRM syncs. Reporting zero for the
        // others would silently make them look infinitely efficient, so the
        // difference between "nothing spent" and "not synced" is kept.
        $spend = $this->spendByChannel($filters, $from, $to);

        return $rows
            ->map(function ($row) use ($spend) {
                $channel = $row->channel;
                $leads   = (int) $row->leads_count;
                $spent   = $spend[$channel] ?? null;

                $per = fn (int $count) => ($spent !== null && $count > 0) ? round($spent / $count, 2) : null;

                return [
                    'channel'        => $channel,
                    'label'          => AcquisitionChannel::label($channel),
                    'icon'           => AcquisitionChannel::icon($channel),
                    'color'          => AcquisitionChannel::color($channel),
                    'is_paid'        => AcquisitionChannel::isPaid($channel),
                    'leads'          => $leads,
                    'contacted'      => (int) $row->contacted_count,
                    'consultation'   => (int) $row->consultation_count,
                    'treatment'      => (int) $row->treatment_count,
                    'won'            => (int) $row->won_count,
                    'lost'           => (int) $row->lost_count,
                    'spend'          => $spent,
                    'spend_known'    => $spent !== null,
                    'cost_per_lead'  => $per($leads),
                    'cost_per_visit' => $per((int) $row->consultation_count),
                    'cost_per_cycle' => $per((int) $row->treatment_count),
                    'lead_to_visit'  => $leads > 0 ? round(((int) $row->consultation_count / $leads) * 100, 1) : 0.0,
                    'visit_to_cycle' => (int) $row->consultation_count > 0
                        ? round(((int) $row->treatment_count / (int) $row->consultation_count) * 100, 1)
                        : 0.0,
                ];
            })
            ->sortByDesc('leads')
            ->values();
    }

    /**
     * Ad spend per channel for the window.
     *
     * Meta spend comes from the synced insights table. Google spend is only
     * available once the Google Ads API connection is configured, so a channel
     * with no known spend is absent from this map rather than zero.
     *
     * @return array<string,float>
     */
    private function spendByChannel(array $filters, Carbon $from, Carbon $to): array
    {
        $spend = [];

        $meta = $this->spend($filters, $from, $to);

        if ($meta > 0) {
            $spend[AcquisitionChannel::META_ADS] = round($meta, 2);
        }

        $google = (float) \App\Models\ChannelSpend::query()
            ->where('channel', AcquisitionChannel::GOOGLE_ADS)
            ->whereBetween('date', [$from, $to])
            ->when(! empty($filters['center_id']), fn ($q) => $q->where('center_id', $filters['center_id']))
            ->sum('spend');

        if ($google > 0) {
            $spend[AcquisitionChannel::GOOGLE_ADS] = round($google, 2);
        }

        return $spend;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function baseQuery(array $filters, Carbon $from, Carbon $to): Builder
    {
        return Lead::query()
            ->whereNull('merged_into_id')
            ->whereBetween('leads.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(! empty($filters['center_id']), fn ($q) => $q->where('center_id', $filters['center_id']))
            ->when(! empty($filters['campaign_id']), fn ($q) => $q->where('campaign_id', $filters['campaign_id']))
            ->when(! empty($filters['adset_id']), fn ($q) => $q->where('adset_id', $filters['adset_id']))
            ->when(! empty($filters['lead_source_id']), fn ($q) => $q->where('lead_source_id', $filters['lead_source_id']))
            ->when(! empty($filters['channel']), fn ($q) => $q->where('leads.channel', $filters['channel']))
            ->when(! empty($filters['assigned_to']), fn ($q) => $q->where('assigned_to', $filters['assigned_to']));
    }

    /**
     * Every stage at or beyond the given slug, so the funnel stays cumulative.
     */
    private function stageIdsFrom(string $slug): array
    {
        static $cache = [];

        if (isset($cache[$slug])) {
            return $cache[$slug];
        }

        $order = PipelineStage::where('slug', $slug)->value('sort_order');

        if ($order === null) {
            return $cache[$slug] = [];
        }

        return $cache[$slug] = PipelineStage::where('sort_order', '>=', $order)
            ->where('is_lost', false)
            ->pluck('id')
            ->all();
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function range(array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from']) : now()->subDays(29);
        $to   = ! empty($filters['to']) ? Carbon::parse($filters['to']) : now();

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
