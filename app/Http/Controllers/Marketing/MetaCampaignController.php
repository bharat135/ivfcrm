<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMetaAccountJob;
use App\Models\Campaign;
use App\Models\Center;
use App\Models\LeadSource;
use App\Models\MetaInsight;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use App\Services\Meta\MetaSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * Meta campaigns, managed from inside the CRM.
 *
 * Writes go to Meta first; the local row is only created/updated once Meta
 * confirms, so the CRM never shows a campaign that does not exist upstream.
 * Campaigns are always created PAUSED so nothing spends before review.
 */
class MetaCampaignController extends Controller
{
    public function __construct(
        private MetaAdsService $ads,
        private MetaClient $client,
        private MetaSyncService $sync,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $from = $request->date('from') ?: now()->subDays(30);
            $to   = $request->date('to') ?: now();

            $stats = MetaInsight::where('level', 'campaign')
                ->whereBetween('date', [$from, $to])
                ->selectRaw('entity_id, SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks, SUM(leads) leads')
                ->groupBy('entity_id')
                ->get()
                ->keyBy('entity_id');

            $query = Campaign::with(['source:id,name', 'center:id,name'])
                ->withCount(['leads', 'adsets'])
                ->when($request->filled('center_id'), fn ($q) => $q->where('center_id', $request->integer('center_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('platform'), fn ($q) => $q->where('platform', $request->string('platform')))
                ->when($request->filled('objective'), fn ($q) => $q->where('objective', $request->string('objective')))
                ->when($request->filled('lead_source_id'), fn ($q) => $q->where('lead_source_id', $request->integer('lead_source_id')));

            return DataTables::of($query)
                ->addColumn('status_badge', fn (Campaign $c) =>
                    '<span class="badge bg-' . $c->statusBadge() . '">' . e($c->effective_status ?: $c->status) . '</span>'
                    . ($c->isMeta() ? ' <i class="bi bi-meta text-primary" title="Synced with Meta"></i>' : '')
                )
                ->addColumn('budget_display', fn (Campaign $c) =>
                    $c->daily_budget ? '₹' . number_format($c->daily_budget, 0) . '<small class="text-muted">/day</small>'
                        : ($c->lifetime_budget ? '₹' . number_format($c->lifetime_budget, 0) . '<small class="text-muted"> total</small>' : '—')
                )
                ->addColumn('spend_period', fn (Campaign $c) => '₹' . number_format((float) ($stats[$c->id]->spend ?? 0), 0))
                ->addColumn('impressions', fn (Campaign $c) => number_format((int) ($stats[$c->id]->impressions ?? 0)))
                ->addColumn('clicks', fn (Campaign $c) => number_format((int) ($stats[$c->id]->clicks ?? 0)))
                ->addColumn('cpl', function (Campaign $c) use ($stats) {
                    $spend = (float) ($stats[$c->id]->spend ?? 0);

                    return $c->leads_count > 0 ? '₹' . number_format($spend / $c->leads_count, 0) : '—';
                })
                ->addColumn('action', fn (Campaign $c) => view('marketing.campaigns._actions', ['campaign' => $c])->render())
                ->rawColumns(['status_badge', 'budget_display', 'action'])
                ->make(true);
        }

        return view('marketing.campaigns.index', [
            'centers'    => Center::where('is_active', true)->orderBy('name')->get(),
            'sources'    => LeadSource::where('is_active', true)->orderBy('name')->get(),
            'agents'     => \App\Models\User::where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Telecaller', 'Counsellor', 'Center Manager', 'Receptionist']))
                ->orderBy('name')->get(['id', 'name']),
            'objectives' => config('meta.ads.objectives'),
            'bidStrategies' => config('meta.ads.bid_strategies'),
            'specialCategories' => config('meta.ads.special_ad_categories'),
            'configured' => $this->client->isAdsConfigured(),
        ]);
    }

    public function show(Campaign $campaign)
    {
        $campaign->load(['adsets' => fn ($q) => $q->withCount('ads', 'leads'), 'source', 'center']);

        $insights = MetaInsight::where('level', 'campaign')
            ->where('entity_id', $campaign->id)
            ->where('date', '>=', now()->subDays(30))
            ->orderBy('date')
            ->get();

        return view('marketing.campaigns.show', [
            'campaign' => $campaign,
            'insights' => $insights,
            'totals'   => [
                'spend'       => $insights->sum('spend'),
                'impressions' => $insights->sum('impressions'),
                'clicks'      => $insights->sum('clicks'),
                'leads'       => $campaign->leads()->count(),
                'reach'       => $insights->sum('reach'),
            ],
            'optimizationGoals' => config('meta.ads.optimization_goals'),
            'billingEvents'     => config('meta.ads.billing_events'),
            'destinationTypes'  => config('meta.ads.destination_types'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:191'],
            'objective'       => ['required', Rule::in(array_keys(config('meta.ads.objectives')))],
            'daily_budget'    => ['nullable', 'numeric', 'min:100'],
            'lifetime_budget' => ['nullable', 'numeric', 'min:100'],
            'bid_strategy'    => ['nullable', Rule::in(array_keys(config('meta.ads.bid_strategies')))],
            'special_ad_categories' => ['nullable', 'array'],
            'start_date'      => ['nullable', 'date'],
            'end_date'        => ['nullable', 'date', 'after_or_equal:start_date'],
            'center_id'       => ['nullable', 'exists:centers,id'],
            'assign_to'       => ['nullable', 'exists:users,id'],
            'lead_source_id'  => ['nullable', 'exists:lead_sources,id'],
            'push_to_meta'    => ['boolean'],
            'utm_source'      => ['nullable', 'string', 'max:191'],
            'utm_medium'      => ['nullable', 'string', 'max:191'],
            'utm_campaign'    => ['nullable', 'string', 'max:191'],
        ]);

        if (empty($data['daily_budget']) && empty($data['lifetime_budget'])) {
            return response()->json([
                'success' => false,
                'message' => 'Set either a daily budget or a lifetime budget.',
            ], 422);
        }

        $externalId = null;

        if ($request->boolean('push_to_meta', true)) {
            if (! $this->client->isAdsConfigured()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Connect a Meta Ad Account under Settings → Integrations before publishing campaigns.',
                ], 422);
            }

            try {
                $response = $this->ads->createCampaign($data);
                $externalId = $response['id'] ?? null;
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $campaign = Campaign::create([
            'name'                  => $data['name'],
            'objective'             => $data['objective'],
            'platform'              => $externalId ? 'meta' : ($request->input('platform', 'meta')),
            'external_id'           => $externalId,
            'external_account_id'   => $externalId ? $this->client->adAccountId() : null,
            'status'                => 'PAUSED',
            'daily_budget'          => $data['daily_budget'] ?? null,
            'lifetime_budget'       => $data['lifetime_budget'] ?? null,
            'budget'                => $data['daily_budget'] ?? $data['lifetime_budget'] ?? null,
            'bid_strategy'          => $data['bid_strategy'] ?? null,
            'special_ad_categories' => $data['special_ad_categories'] ?? [],
            'start_date'            => $data['start_date'] ?? null,
            'end_date'              => $data['end_date'] ?? null,
            'center_id'             => $data['center_id'] ?? null,
            'lead_source_id'        => $data['lead_source_id'] ?? null,
            'utm_source'            => $data['utm_source'] ?? 'facebook',
            'utm_medium'            => $data['utm_medium'] ?? 'paid_social',
            'utm_campaign'          => $data['utm_campaign'] ?? $data['name'],
            'is_active'             => false,
            'synced_at'             => $externalId ? now() : null,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => $externalId
                ? 'Campaign created on Meta (paused). Add an ad set next.'
                : 'Campaign saved locally.',
            'id'       => $campaign->id,
            'redirect' => route('marketing.campaigns.show', $campaign),
        ]);
    }

    public function edit(Campaign $campaign): JsonResponse
    {
        return response()->json($campaign);
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:191'],
            'daily_budget'    => ['nullable', 'numeric', 'min:100'],
            'lifetime_budget' => ['nullable', 'numeric', 'min:100'],
            'bid_strategy'    => ['nullable', 'string'],
            'center_id'       => ['nullable', 'exists:centers,id'],
            'assign_to'       => ['nullable', 'exists:users,id'],
            'lead_source_id'  => ['nullable', 'exists:lead_sources,id'],
            'end_date'        => ['nullable', 'date'],
        ]);

        if ($campaign->external_id) {
            try {
                $this->ads->updateCampaign($campaign->external_id, $data);
            } catch (MetaException $e) {
                $campaign->update(['sync_error' => $e->displayMessage()]);

                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $campaign->update(array_merge($data, [
            'budget'     => $data['daily_budget'] ?? $data['lifetime_budget'] ?? $campaign->budget,
            'synced_at'  => $campaign->external_id ? now() : $campaign->synced_at,
            'sync_error' => null,
        ]));

        return response()->json(['success' => true, 'message' => 'Campaign updated.']);
    }

    /**
     * Play / pause straight from the list.
     */
    public function toggleStatus(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'PAUSED', 'ARCHIVED'])],
        ]);

        if ($campaign->external_id) {
            try {
                $this->ads->setStatus($campaign->external_id, $data['status']);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $campaign->update([
            'status'    => $data['status'],
            'is_active' => $data['status'] === 'ACTIVE',
            'synced_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Campaign ' . strtolower($data['status']) . '.',
        ]);
    }

    public function destroy(Campaign $campaign): JsonResponse
    {
        if ($campaign->leads()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete: leads are attributed to this campaign. Archive it instead.',
            ], 422);
        }

        if ($campaign->external_id) {
            try {
                $this->ads->deleteCampaign($campaign->external_id);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $campaign->delete();

        return response()->json(['success' => true, 'message' => 'Campaign deleted.']);
    }

    /**
     * Pull the account's structure and insights on demand.
     */
    public function syncNow(Request $request): JsonResponse
    {
        if (! $this->client->isAdsConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Meta Ad Account is not configured.',
            ], 422);
        }

        // Run inline when asked so the user sees results immediately.
        if ($request->boolean('inline')) {
            try {
                $counts = $this->sync->syncStructure();
                $this->sync->syncInsights(now()->subDays(30), now());

                return response()->json([
                    'success' => true,
                    'message' => "Synced {$counts['campaigns']} campaigns, {$counts['adsets']} ad sets, {$counts['ads']} ads.",
                ]);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        SyncMetaAccountJob::dispatch(['structure', 'insights']);

        return response()->json(['success' => true, 'message' => 'Sync queued.']);
    }
}
