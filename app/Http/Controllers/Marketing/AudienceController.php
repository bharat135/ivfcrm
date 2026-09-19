<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Services\Meta\AudienceSyncService;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marketing → Audiences.
 *
 * Turns the CRM's own patient list into Meta targeting: retarget the couples
 * who stalled, stop advertising to the ones already in treatment, and build a
 * lookalike from the ones who converted.
 *
 * The mapping from segment to Meta audience id is stored in settings rather
 * than a table — there are four of them and they are configuration, not data.
 */
class AudienceController extends Controller
{
    public function __construct(
        private AudienceSyncService $audiences,
        private MetaAdsService $ads,
        private MetaClient $client,
        private SettingsRepository $settings,
    ) {}

    public function index(Request $request)
    {
        $centerId = $request->integer('center_id') ?: null;
        $linked   = $this->settings->get('meta', 'audiences', []) ?: [];

        $segments = [];

        foreach (AudienceSyncService::SEGMENTS as $key => [$label, $description]) {
            $segments[$key] = [
                'key'         => $key,
                'label'       => $label,
                'description' => $description,
                'size'        => $this->audiences->size($key, $centerId),
                'audience_id' => $linked[$key] ?? null,
            ];
        }

        return view('marketing.audiences.index', [
            'segments'  => $segments,
            'centers'   => Center::where('is_active', true)->orderBy('name')->get(),
            'centerId'  => $centerId,
            'connected' => $this->client->isAdsConfigured(),
            'remote'    => $this->remoteAudiences(),
        ]);
    }

    /**
     * Build or refresh one segment's audience on Meta.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'segment'   => ['required', 'string', 'in:' . implode(',', array_keys(AudienceSyncService::SEGMENTS))],
            'center_id' => ['nullable', 'exists:centers,id'],
        ]);

        $linked = $this->settings->get('meta', 'audiences', []) ?: [];

        try {
            $result = $this->audiences->sync(
                $data['segment'],
                $linked[$data['segment']] ?? null,
                $data['center_id'] ?? null,
            );
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // Remember the audience so the next sync updates it in place instead of
        // creating a second one with the same name.
        $linked[$data['segment']] = $result['audience_id'];
        $this->settings->set('meta', 'audiences', $linked);

        return response()->json([
            'success' => true,
            'message' => sprintf(
                'Uploaded %s people in %d batch(es). %s skipped for having no phone or email.',
                number_format($result['uploaded']),
                $result['batches'],
                number_format($result['skipped']),
            ),
            'result'  => $result,
        ]);
    }

    /**
     * Seed a lookalike from a populated audience.
     */
    public function lookalike(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience_id' => ['required', 'string', 'max:64'],
            'ratio'       => ['nullable', 'numeric', 'min:0.01', 'max:0.2'],
            'country'     => ['nullable', 'string', 'size:2'],
        ]);

        try {
            $created = $this->audiences->createLookalike(
                $data['audience_id'],
                (float) ($data['ratio'] ?? 0.01),
                strtoupper($data['country'] ?? 'IN'),
            );
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Lookalike audience created. Meta will take a few hours to populate it.',
            'id'      => $created['id'] ?? null,
        ]);
    }

    /**
     * Unlink a segment without deleting the audience on Meta — an audience in
     * use by a live ad set must not vanish because somebody tidied up here.
     */
    public function unlink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'segment' => ['required', 'string', 'in:' . implode(',', array_keys(AudienceSyncService::SEGMENTS))],
        ]);

        $linked = $this->settings->get('meta', 'audiences', []) ?: [];
        unset($linked[$data['segment']]);

        $this->settings->set('meta', 'audiences', $linked);

        return response()->json([
            'success' => true,
            'message' => 'Unlinked. The audience still exists in Meta Ads Manager.',
        ]);
    }

    /**
     * Audiences that already exist on the ad account, with their live sizes.
     *
     * @return array<int,array<string,mixed>>
     */
    private function remoteAudiences(): array
    {
        if (! $this->client->isAdsConfigured()) {
            return [];
        }

        try {
            return array_map(fn ($audience) => [
                'id'       => $audience['id'] ?? '',
                'name'     => $audience['name'] ?? '',
                'subtype'  => $audience['subtype'] ?? '',
                'size'     => $audience['approximate_count_lower_bound'] ?? null,
                'status'   => $audience['delivery_status']['description'] ?? null,
                'updated'  => $audience['time_updated'] ?? null,
            ], $this->ads->listCustomAudiences());
        } catch (MetaException $e) {
            // A missing ads_management permission must not blank the page; the
            // local segment sizes are still useful on their own.
            return [];
        }
    }
}
