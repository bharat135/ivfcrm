<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Adset;
use App\Models\LandingPage;
use App\Models\MetaInsight;
use App\Models\MetaLeadForm;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * Ads and their creatives.
 *
 * Creating an ad is a two-step Graph operation: upload the image, build an
 * ad creative, then attach that creative to an ad. Both steps happen here so
 * the user only fills in one form.
 */
class MetaAdController extends Controller
{
    public function __construct(
        private MetaAdsService $ads,
        private MetaClient $client,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $from = $request->date('from') ?: now()->subDays(30);
            $to   = $request->date('to') ?: now();

            $stats = MetaInsight::where('level', 'ad')
                ->whereBetween('date', [$from, $to])
                ->selectRaw('entity_id, SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks, SUM(leads) leads')
                ->groupBy('entity_id')
                ->get()
                ->keyBy('entity_id');

            $query = Ad::with(['adset:id,name,campaign_id', 'adset.campaign:id,name', 'leadForm:id,name'])
                ->withCount('leads')
                ->when($request->filled('adset_id'), fn ($q) => $q->where('adset_id', $request->integer('adset_id')))
                ->when($request->filled('campaign_id'), fn ($q) =>
                    $q->whereHas('adset', fn ($a) => $a->where('campaign_id', $request->integer('campaign_id'))))
                ->when($request->filled('center_id'), fn ($q) =>
                    $q->whereHas('adset.campaign', fn ($c) => $c->where('center_id', $request->integer('center_id'))))
                ->when($request->filled('lead_form_id'), fn ($q) => $q->where('lead_form_id', $request->integer('lead_form_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

            return DataTables::of($query)
                ->addColumn('status_badge', fn (Ad $a) =>
                    '<span class="badge bg-' . $a->statusBadge() . '">' . e($a->effective_status ?: $a->status) . '</span>')
                ->addColumn('thumb', fn (Ad $a) => $a->thumbnail_url
                    ? '<img src="' . e($a->thumbnail_url) . '" class="rounded" style="width:44px;height:44px;object-fit:cover">'
                    : '<div class="bg-light rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px"><i class="bi bi-image text-muted"></i></div>')
                ->addColumn('adset_name', fn (Ad $a) => e($a->adset?->name ?? '—'))
                ->addColumn('campaign_name', fn (Ad $a) => e($a->adset?->campaign?->name ?? '—'))
                ->addColumn('spend_period', fn (Ad $a) => '₹' . number_format((float) ($stats[$a->id]->spend ?? 0), 0))
                ->addColumn('impressions', fn (Ad $a) => number_format((int) ($stats[$a->id]->impressions ?? 0)))
                ->addColumn('ctr', function (Ad $a) use ($stats) {
                    $impressions = (int) ($stats[$a->id]->impressions ?? 0);
                    $clicks      = (int) ($stats[$a->id]->clicks ?? 0);

                    return $impressions > 0 ? round(($clicks / $impressions) * 100, 2) . '%' : '—';
                })
                ->addColumn('action', fn (Ad $a) => view('marketing.ads._actions', ['ad' => $a])->render())
                ->rawColumns(['status_badge', 'thumb', 'action'])
                ->make(true);
        }

        return view('marketing.ads.index', [
            'adsets'       => Adset::with('campaign:id,name')->orderByDesc('id')->get(),
            'campaigns'    => \App\Models\Campaign::orderByDesc('id')->get(['id', 'name']),
            'centers'      => \App\Models\Center::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'leadForms'    => MetaLeadForm::active()->get(['id', 'external_id', 'name']),
            'landingPages' => LandingPage::published()->get(['id', 'name', 'slug']),
            'configured'   => $this->client->isAdsConfigured(),
            'ctas'         => $this->callToActions(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'adset_id'       => ['required', 'exists:adsets,id'],
            'name'           => ['required', 'string', 'max:191'],
            'headline'       => ['required', 'string', 'max:255'],
            'message'        => ['required', 'string', 'max:2000'],
            'description'    => ['nullable', 'string', 'max:500'],
            'destination'    => ['required', Rule::in(['lead_form', 'landing_page', 'whatsapp', 'website'])],
            'lead_form_id'   => ['nullable', 'exists:meta_lead_forms,id'],
            'landing_page_id'=> ['nullable', 'exists:landing_pages,id'],
            'link'           => ['nullable', 'url'],
            'call_to_action' => ['nullable', 'string', 'max:60'],
            'image'          => ['nullable', 'image', 'max:5120'],
        ]);

        $adset = Adset::findOrFail($data['adset_id']);

        if (! $adset->external_id) {
            return response()->json([
                'success' => false,
                'message' => 'This ad set only exists locally. Publish it to Meta before adding ads.',
            ], 422);
        }

        // 1. Image upload (optional but strongly recommended).
        $imageHash = null;
        if ($request->hasFile('image')) {
            try {
                $imageHash = $this->ads->uploadAdImage($request->file('image')->getRealPath());
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => 'Image upload failed: ' . $e->displayMessage()], 422);
            }
        }

        // 2. Destination decides link + call-to-action.
        $creativePayload = [
            'name'        => $data['name'] . ' creative',
            'headline'    => $data['headline'],
            'message'     => $data['message'],
            'description' => $data['description'] ?? null,
            'image_hash'  => $imageHash,
            'page_id'     => $this->client->pageId(),
            'call_to_action' => $data['call_to_action'] ?? 'LEARN_MORE',
        ];

        switch ($data['destination']) {
            case 'lead_form':
                $form = MetaLeadForm::find($data['lead_form_id']);
                if (! $form) {
                    return response()->json(['success' => false, 'message' => 'Select a lead form.'], 422);
                }
                $creativePayload['lead_form_id']   = $form->external_id;
                $creativePayload['call_to_action'] = $data['call_to_action'] ?? 'SIGN_UP';
                break;

            case 'landing_page':
                $page = LandingPage::find($data['landing_page_id']);
                if (! $page) {
                    return response()->json(['success' => false, 'message' => 'Select a landing page.'], 422);
                }
                $creativePayload['link'] = $page->publicUrl() . '?utm_source=facebook&utm_medium=paid_social';
                break;

            case 'whatsapp':
                $creativePayload['whatsapp_number'] = $this->client->credential('phone_number_id');
                break;

            default:
                if (empty($data['link'])) {
                    return response()->json(['success' => false, 'message' => 'A destination URL is required.'], 422);
                }
                $creativePayload['link'] = $data['link'];
        }

        try {
            $creative = $this->ads->createAdCreative($creativePayload);

            $response = $this->ads->createAd([
                'name'        => $data['name'],
                'adset_id'    => $adset->external_id,
                'creative_id' => $creative['id'],
            ]);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $ad = Ad::create([
            'adset_id'             => $adset->id,
            'name'                 => $data['name'],
            'external_id'          => $response['id'] ?? null,
            'external_creative_id' => $creative['id'] ?? null,
            'status'               => 'PAUSED',
            'meta_lead_form_id'    => $data['lead_form_id'] ?? null,
            'creative'             => [
                'object_story_spec' => [
                    'link_data' => [
                        'message'     => $data['message'],
                        'name'        => $data['headline'],
                        'description' => $data['description'] ?? null,
                    ],
                ],
            ],
            'synced_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ad created on Meta (paused). Review the preview, then activate it.',
            'id'      => $ad->id,
        ]);
    }

    public function edit(Ad $ad): JsonResponse
    {
        return response()->json($ad->load('adset:id,name', 'leadForm:id,name'));
    }

    public function update(Request $request, Ad $ad): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:191']]);

        if ($ad->external_id) {
            try {
                $this->ads->updateAd($ad->external_id, $data);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $ad->update($data + ['synced_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Ad updated.']);
    }

    public function toggleStatus(Request $request, Ad $ad): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'PAUSED', 'ARCHIVED'])],
        ]);

        if ($ad->external_id) {
            try {
                $this->ads->setStatus($ad->external_id, $data['status']);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $ad->update(['status' => $data['status'], 'synced_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Ad ' . strtolower($data['status']) . '.']);
    }

    public function destroy(Ad $ad): JsonResponse
    {
        if ($ad->external_id) {
            try {
                $this->ads->deleteAd($ad->external_id);
            } catch (MetaException $e) {
                return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
            }
        }

        $ad->delete();

        return response()->json(['success' => true, 'message' => 'Ad deleted.']);
    }

    /**
     * Meta-rendered preview iframe, shown in the ad drawer.
     */
    public function preview(Request $request, Ad $ad): JsonResponse
    {
        if (! $ad->external_id) {
            return response()->json(['success' => false, 'message' => 'This ad has not been published to Meta.'], 422);
        }

        $format = $request->input('format', 'DESKTOP_FEED_STANDARD');

        try {
            $html = $this->ads->adPreview($ad->external_id, $format);
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json(['success' => true, 'html' => $html]);
    }

    private function callToActions(): array
    {
        return [
            'LEARN_MORE'       => 'Learn More',
            'SIGN_UP'          => 'Sign Up',
            'BOOK_TRAVEL'      => 'Book Now',
            'CONTACT_US'       => 'Contact Us',
            'GET_QUOTE'        => 'Get Quote',
            'CALL_NOW'         => 'Call Now',
            'APPLY_NOW'        => 'Apply Now',
            'WHATSAPP_MESSAGE' => 'Send WhatsApp Message',
            'MESSAGE_PAGE'     => 'Send Message',
            'SUBSCRIBE'        => 'Subscribe',
        ];
    }
}
