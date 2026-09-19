<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Adset;
use App\Models\Campaign;
use App\Models\Center;
use App\Models\Conversation;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Message;
use App\Models\MetaInsight;
use App\Services\Marketing\FunnelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Marketing analytics: ad performance from meta_insights joined against real
 * CRM outcomes (leads, bookings, visits, conversions) so cost is measured
 * against revenue-bearing events rather than platform-reported conversions.
 */
class AnalyticsController extends Controller
{
    public function __construct(private FunnelService $funnel) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        return view('marketing.analytics.index', [
            'filters'   => $filters,
            'centers'   => Center::where('is_active', true)->orderBy('name')->get(),
            'campaigns' => Campaign::orderByDesc('id')->get(['id', 'name']),
            'sources'   => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'summary'   => $this->summary($filters),
        ]);
    }

    /**
     * Headline KPI cards.
     */
    public function summary(array $filters): array
    {
        [$from, $to] = [Carbon::parse($filters['from']), Carbon::parse($filters['to'])];

        $insights = MetaInsight::where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->when($filters['campaign_id'], fn ($q) => $q->where('entity_id', $filters['campaign_id']))
            ->selectRaw('SUM(spend) spend, SUM(impressions) impressions, SUM(reach) reach, SUM(clicks) clicks, SUM(leads) platform_leads')
            ->first();

        $leadQuery = Lead::query()
            ->whereNull('merged_into_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($filters['center_id'], fn ($q) => $q->where('center_id', $filters['center_id']))
            ->when($filters['campaign_id'], fn ($q) => $q->where('campaign_id', $filters['campaign_id']))
            ->when($filters['lead_source_id'], fn ($q) => $q->where('lead_source_id', $filters['lead_source_id']));

        $crmLeads = (clone $leadQuery)->count();
        $won      = (clone $leadQuery)->where('status', 'won')->count();
        $spend    = (float) ($insights->spend ?? 0);

        $previous = $this->previousPeriodSpend($filters, $from, $to);

        return [
            'spend'         => $spend,
            'spend_change'  => $this->percentChange($spend, $previous['spend']),
            'impressions'   => (int) ($insights->impressions ?? 0),
            'reach'         => (int) ($insights->reach ?? 0),
            'clicks'        => (int) ($insights->clicks ?? 0),
            'ctr'           => ($insights->impressions ?? 0) > 0
                ? round((($insights->clicks ?? 0) / $insights->impressions) * 100, 2) : 0,
            'cpc'           => ($insights->clicks ?? 0) > 0 ? round($spend / $insights->clicks, 2) : 0,
            'cpm'           => ($insights->impressions ?? 0) > 0 ? round(($spend / $insights->impressions) * 1000, 2) : 0,
            'platform_leads'=> (int) ($insights->platform_leads ?? 0),
            'crm_leads'     => $crmLeads,
            'crm_leads_change' => $this->percentChange($crmLeads, $previous['leads']),
            'cpl'           => $crmLeads > 0 ? round($spend / $crmLeads, 2) : 0,
            'won'           => $won,
            'cac'           => $won > 0 ? round($spend / $won, 2) : 0,
            'conversion_rate' => $crmLeads > 0 ? round(($won / $crmLeads) * 100, 2) : 0,
            'whatsapp_conversations' => Conversation::where('channel', 'whatsapp')
                ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
            'whatsapp_messages' => Message::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
        ];
    }

    /**
     * Daily spend vs leads vs conversions, for the main chart.
     */
    public function trend(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        [$from, $to] = [Carbon::parse($filters['from']), Carbon::parse($filters['to'])];

        $spend = MetaInsight::where('level', 'campaign')
            ->whereBetween('date', [$from, $to])
            ->when($filters['campaign_id'], fn ($q) => $q->where('entity_id', $filters['campaign_id']))
            ->selectRaw('date, SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => $row->date->toDateString());

        $leads = Lead::query()
            ->whereNull('merged_into_id')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($filters['center_id'], fn ($q) => $q->where('center_id', $filters['center_id']))
            ->when($filters['campaign_id'], fn ($q) => $q->where('campaign_id', $filters['campaign_id']))
            ->selectRaw('DATE(created_at) d, COUNT(*) c, SUM(status = "won") w')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $labels = $series = [];
        $series = ['spend' => [], 'leads' => [], 'won' => [], 'clicks' => [], 'cpl' => []];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $daySpend = round((float) ($spend[$key]->spend ?? 0), 2);
            $dayLeads = (int) ($leads[$key]->c ?? 0);

            $labels[] = $date->format('d M');
            $series['spend'][]  = $daySpend;
            $series['leads'][]  = $dayLeads;
            $series['won'][]    = (int) ($leads[$key]->w ?? 0);
            $series['clicks'][] = (int) ($spend[$key]->clicks ?? 0);
            $series['cpl'][]    = $dayLeads > 0 ? round($daySpend / $dayLeads, 2) : 0;
        }

        return response()->json(['labels' => $labels, 'series' => $series]);
    }

    /**
     * Performance table at campaign / adset / ad level.
     */
    public function breakdown(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $level   = $request->input('level', 'campaign');
        [$from, $to] = [Carbon::parse($filters['from']), Carbon::parse($filters['to'])];

        $stats = MetaInsight::where('level', $level)
            ->whereBetween('date', [$from, $to])
            ->whereNotNull('entity_id')
            ->selectRaw('entity_id, SUM(spend) spend, SUM(impressions) impressions, SUM(reach) reach, SUM(clicks) clicks')
            ->groupBy('entity_id')
            ->get()
            ->keyBy('entity_id');

        $model = match ($level) {
            'adset' => Adset::class,
            'ad'    => Ad::class,
            default => Campaign::class,
        };

        $leadColumn = match ($level) {
            'adset' => 'adset_id',
            'ad'    => 'ad_id',
            default => 'campaign_id',
        };

        $rows = $model::query()
            ->whereIn('id', $stats->keys()->all() ?: [0])
            ->withCount([
                'leads as leads_count' => fn ($q) => $q->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]),
                'leads as won_count'   => fn ($q) => $q
                    ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                    ->where('status', 'won'),
            ])
            ->get()
            ->map(function ($row) use ($stats) {
                $stat  = $stats[$row->id] ?? null;
                $spend = (float) ($stat->spend ?? 0);
                $impressions = (int) ($stat->impressions ?? 0);
                $clicks = (int) ($stat->clicks ?? 0);

                return [
                    'id'          => $row->id,
                    'name'        => $row->name,
                    'status'      => $row->effective_status ?: $row->status,
                    'badge'       => $row->statusBadge(),
                    'spend'       => round($spend, 2),
                    'impressions' => $impressions,
                    'reach'       => (int) ($stat->reach ?? 0),
                    'clicks'      => $clicks,
                    'ctr'         => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
                    'cpc'         => $clicks > 0 ? round($spend / $clicks, 2) : 0,
                    'cpm'         => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : 0,
                    'leads'       => $row->leads_count,
                    'cpl'         => $row->leads_count > 0 ? round($spend / $row->leads_count, 2) : null,
                    'won'         => $row->won_count,
                    'cac'         => $row->won_count > 0 ? round($spend / $row->won_count, 2) : null,
                    'conv_rate'   => $row->leads_count > 0 ? round(($row->won_count / $row->leads_count) * 100, 1) : 0,
                ];
            })
            ->sortByDesc('spend')
            ->values();

        return response()->json(['data' => $rows, 'level' => $leadColumn]);
    }

    /**
     * Funnel view: Lead → Contacted → Interested → Booked → Visited → Won.
     */
    public function funnel(Request $request)
    {
        $filters = $this->filters($request);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'funnel'   => $this->funnel->build($filters),
                'trend'    => $this->funnel->trend($filters),
                'channels' => $this->funnel->byChannel($filters),
            ]);
        }

        return view('marketing.analytics.funnel', [
            'filters'   => $filters,
            'funnel'    => $this->funnel->build($filters),
            'trend'     => $this->funnel->trend($filters),
            'campaigns' => $this->funnel->byCampaign($filters),

            // Google versus Meta, costed against the couples who actually
            // started treatment. Only possible because a lead is a patient.
            'channels'  => $this->funnel->byChannel($filters),

            'centers'   => Center::where('is_active', true)->orderBy('name')->get(),
            'allCampaigns' => Campaign::orderByDesc('id')->get(['id', 'name']),
            'sources'   => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'channelOptions' => \App\Support\AcquisitionChannel::options(),
        ]);
    }

    /**
     * Landing page performance (views, submissions, conversion rate).
     */
    public function landingPages(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        [$from, $to] = [Carbon::parse($filters['from']), Carbon::parse($filters['to'])];

        $pages = LandingPage::withCount([
            'submissionRecords as period_submissions' => fn ($q) =>
                $q->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]),
        ])->orderByDesc('views')->get();

        return response()->json([
            'data' => $pages->map(fn (LandingPage $p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'url'         => $p->publicUrl(),
                'published'   => $p->is_published,
                'views'       => $p->views,
                'submissions' => $p->submissions,
                'period'      => $p->period_submissions,
                'rate'        => $p->conversionRate(),
            ]),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function filters(Request $request): array
    {
        return [
            'from'           => $request->input('from', now()->subDays(29)->toDateString()),
            'to'             => $request->input('to', now()->toDateString()),
            'center_id'      => $request->integer('center_id') ?: null,
            'campaign_id'    => $request->integer('campaign_id') ?: null,
            'adset_id'       => $request->integer('adset_id') ?: null,
            'lead_source_id' => $request->integer('lead_source_id') ?: null,
            'channel'        => $request->input('channel') ?: null,
        ];
    }

    /**
     * Same-length window immediately before the selected one, for deltas.
     */
    private function previousPeriodSpend(array $filters, Carbon $from, Carbon $to): array
    {
        $days = max($from->diffInDays($to), 1);
        $prevTo   = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days);

        $spend = (float) MetaInsight::where('level', 'campaign')
            ->whereBetween('date', [$prevFrom, $prevTo])
            ->when($filters['campaign_id'], fn ($q) => $q->where('entity_id', $filters['campaign_id']))
            ->sum('spend');

        $leads = Lead::whereNull('merged_into_id')
            ->whereBetween('created_at', [$prevFrom->startOfDay(), $prevTo->endOfDay()])
            ->when($filters['center_id'], fn ($q) => $q->where('center_id', $filters['center_id']))
            ->when($filters['campaign_id'], fn ($q) => $q->where('campaign_id', $filters['campaign_id']))
            ->count();

        return ['spend' => $spend, 'leads' => $leads];
    }

    private function percentChange(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
