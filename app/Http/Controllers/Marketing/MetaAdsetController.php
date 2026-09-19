<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Adset;
use App\Models\Campaign;
use App\Models\MetaInsight;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * Ad sets — where budget, schedule, optimisation goal and audience live.
 *
 * The targeting form is deliberately simplified (age, gender, locations,
 * interests, platforms) and translated into Meta's targeting spec by
 * MetaAdsService::buildTargeting().
 */
class MetaAdsetController extends Controller
{
    public function __construct(
        private MetaAdsService $ads,
        private MetaClient $client,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $stats = MetaInsight::where('level', 'adset')
                ->where('date', '>=', now()->subDays(30))
                ->selectRaw('entity_id, SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks')
                ->groupBy('entity_id')
                ->get()
                ->keyBy('entity_id');

            $query = Adset::with('campaign:id,name')
                ->withCount(['ads', 'leads'])
                ->when($request->filled('campaign_id'), fn ($q) => $q->where('campaign_id', $request->integer('campaign_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

            return DataTables::of($query)
                ->addColumn('status_badge', fn (Adset $a) =>
                    '<span class="badge bg-' . $a->statusBadge() . '">' . e($a->effective_status ?: $a->status) . '</span>')
                ->addColumn('campaign_name', fn (Adset $a) => e($a->campaign?->name ?? '—'))
                ->addColumn('budget_display', fn (Adset $a) =>
                    $a->daily_budget ? '₹' . number_format($a->daily_budget, 0) . '<small class="text-muted">/day</small>'
                        : ($a->lifetime_budget ? '₹' . number_format($a->lifetime_budget, 0) : '—'))
                ->addColumn('targeting_summary', fn (Adset $a) => e($a->targetingSummary()))
                ->addColumn('spend_period', fn (Adset $a) => '₹' . number_format((float) ($stats[$a->id]->spend ?? 0), 0))
                ->addColumn('action', fn (Adset $a) => view('marketing.adsets._actions', ['adset' => $a])->render())
                ->rawColumns(['status_badge', 'budget_display', 'action'])
                ->make(true);
        }

        return view('marketing.adsets.index', [
            'campaigns'         => Campaign::orderByDesc('id')->get(['id', 'name']),
            'optimizationGoals' => config('meta.ads.optimization_goals'),
            'billingEvents'     => config('meta.ads.billing_events'),
            'destinationTypes'  => config('meta.ads.destination_types'),
            'leadForms'         => \App\Models\MetaLeadForm::active()->get(['id', 'external_id', 'name']),
            'configured'        => $this->client->isAdsConfigured(),
        ]);
    }

    public function show(Adset $adset)
    {
        $adset->load(['campaign', 'ads' => fn ($q) => $q->withCount('leads')]);

        $insights = MetaInsight::where('level', 'adset')
            ->where('entity_id', $adset->id)
            ->where('date', '>=', now()->subDays(30))
            ->orderBy('date')
            ->get();

        return view('marketing.adsets.show', compact('adset', 'insights'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $campaign = Campaign::findOrFail($data['campaign_id']);

        if (! $campaign->external_id) {
            return response()->json([
                'success' => false,
                'message' => 'This campaign only exists locally. Publish it to Meta before adding ad sets.',
            ], 422);
        }

        $targeting = $this->targetingFrom($request);

        try {
            $response = $this->ads->createAdset(array_merge($data, [
                'campaign_id' => $campaign->external_id,
                'targeting'   => $targeting,
                'page_id'     => $this->client->pageId(),
                'pixel_id'    => $this->client->credential('pixel_id'),
            ]));
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $adset = Adset::create([
            'campaign_id'       => $campaign->id,
            'name'              => $data['name'],
            'external_id'       => $response['id'] ?? null,
            'status'            => 'PAUSED',
            'daily_budget'      => $data['daily_budget'] ?? null,
            'lifetime_budget'   => $data['lifetime_budget'] ?? null,
            'optimization_goal' => $data['optimization_goal'],
            'billing_event'     => $data['billing_event'] ?? 'IMPRESSIONS',
            'bid_amount'        => $data['bid_amount'] ?? null,
            'destination_type'  => $data['destination_type'] ?? null,
            'targeting'         => $this->ads->buildTargeting($targeting),
            'start_time'        => $data['start_time'] ?? null,
            'end_time'          => $data['end_time'] ?? null,
            'synced_at'         => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ad set created on Meta (paused).',
            'id'      => $adset->id,
        ]);
    }

    public function edit(Adset $adset): JsonResponse
    {
        $targeting = $adset->targeting ?? [];

        return response()->json([
            'adset'     => $adset,
            'targeting' => [
                'age_min'   => $targeting['age_min'] ?? 21,
                'age_max'   => $targeting['age_max'] ?? 45,
                'genders'   => $targeting['genders'][0] ?? 0,
                'cities'    => $targeting['geo_locations']['cities'] ?? [],
                'countries' => $targeting['geo_locations']['countries'] ?? ['IN'],
                'interests' => $targeting['flexible_spec'][0]['interests'] ?? [],
                'platforms' => $targeting['publisher_platforms'] ?? ['facebook', 'instagram'],
            ],
        ]);
    }

    public function update(Request $request, Adset $adset): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:191'],
            'daily_budget'    => ['nullable', 'numeric', 'min:50'],
            'lifetime_budget' => ['nullable', 'numeric', 'min:50'],
            'bid_amount'      => ['nullable', 'numeric', 'min:1'],
            'end_time'        => ['nullable', 'date'],
        ]);

        $payload = $data;

        if ($request->filled('age_min') || $request->filled('cities')) {
            $payload['targeting'] = $this->targetingFrom($request);
        }

        if ($adset->external_id) {
            try {
                $this->ads->updateAdset($adset->external_id, $payload);
            } catch (MetaException $e) {
                $adset->update(['sync_error' => $e->displayMessage()]);

                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $adset->update(array_merge($data, [
            'targeting'  => isset($payload['targeting']) ? $this->ads->buildTargeting($payload['targeting']) : $adset->targeting,
            'synced_at'  => now(),
            'sync_error' => null,
        ]));

        return response()->json(['success' => true, 'message' => 'Ad set updated.']);
    }

    public function toggleStatus(Request $request, Adset $adset): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'PAUSED', 'ARCHIVED'])],
        ]);

        if ($adset->external_id) {
            try {
                $this->ads->setStatus($adset->external_id, $data['status']);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $adset->update(['status' => $data['status'], 'synced_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Ad set ' . strtolower($data['status']) . '.']);
    }

    public function destroy(Adset $adset): JsonResponse
    {
        if ($adset->external_id) {
            try {
                $this->ads->deleteAdset($adset->external_id);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $adset->delete();

        return response()->json(['success' => true, 'message' => 'Ad set deleted.']);
    }

    // -----------------------------------------------------------------
    // Targeting helpers (typeahead + live reach estimate)
    // -----------------------------------------------------------------

    public function searchInterests(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2']]);

        try {
            return response()->json(['results' => $this->ads->searchInterests($request->string('q'))]);
        } catch (MetaException $e) {
            return response()->json(['results' => [], 'message' => $e->displayMessage()]);
        }
    }

    public function searchLocations(Request $request): JsonResponse
    {
        $request->validate([
            'q'    => ['required', 'string', 'min:2'],
            'type' => ['nullable', Rule::in(['city', 'region', 'country', 'zip'])],
        ]);

        try {
            return response()->json([
                'results' => $this->ads->searchLocations($request->string('q'), $request->input('type', 'city')),
            ]);
        } catch (MetaException $e) {
            return response()->json(['results' => [], 'message' => $e->displayMessage()]);
        }
    }

    public function reachEstimate(Request $request): JsonResponse
    {
        try {
            $estimate = $this->ads->reachEstimate(
                $this->targetingFrom($request),
                $request->input('optimization_goal', 'LEAD_GENERATION'),
            );

            return response()->json(['success' => true, 'estimate' => $estimate]);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }
    }

    private function targetingFrom(Request $request): array
    {
        return [
            'age_min'   => $request->integer('age_min', 21),
            'age_max'   => $request->integer('age_max', 45),
            'genders'   => $request->integer('genders', 0),
            'countries' => $request->input('countries', ['IN']),
            'cities'    => $request->input('cities', []),
            'interests' => $request->input('interests', []),
            'platforms' => $request->input('platforms', ['facebook', 'instagram']),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'campaign_id'       => ['required', 'exists:campaigns,id'],
            'name'              => ['required', 'string', 'max:191'],
            'optimization_goal' => ['required', Rule::in(array_keys(config('meta.ads.optimization_goals')))],
            'billing_event'     => ['nullable', Rule::in(array_keys(config('meta.ads.billing_events')))],
            'destination_type'  => ['nullable', Rule::in(array_keys(config('meta.ads.destination_types')))],
            'daily_budget'      => ['nullable', 'numeric', 'min:50'],
            'lifetime_budget'   => ['nullable', 'numeric', 'min:50'],
            'bid_amount'        => ['nullable', 'numeric', 'min:1'],
            'start_time'        => ['nullable', 'date'],
            'end_time'          => ['nullable', 'date', 'after:start_time'],
        ]);
    }
}
