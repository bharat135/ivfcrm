<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Rules\IndianMobile;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public lead intake API (v1), authenticated with a Sanctum bearer token an
 * admin generates under Settings → API. Any external system — a website form,
 * a landing page, a partner feed — can push a lead in with its full detail and
 * the CRM dedupes, attributes, assigns and opens the patient file exactly as
 * for a lead from any other channel.
 *
 * Docs are served live at /developers.
 */
class LeadApiController extends Controller
{
    public function __construct(private LeadService $service) {}

    /**
     * POST /api/v1/leads — create (or dedupe onto) a lead with all details.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['nullable', 'string', 'max:191'],
            'mobile'         => ['required', 'string', 'max:20', new IndianMobile()],
            'alt_mobile'     => ['nullable', 'string', 'max:20', new IndianMobile()],
            'email'          => ['nullable', 'email', 'max:191'],
            'gender'         => ['nullable', 'in:male,female,other'],
            'age'            => ['nullable', 'integer', 'min:1', 'max:120'],
            'wife_age'       => ['nullable', 'integer', 'min:1', 'max:100'],
            'husband_age'    => ['nullable', 'integer', 'min:1', 'max:100'],
            'years_married'  => ['nullable', 'integer', 'min:0', 'max:80'],
            'years_trying'   => ['nullable', 'integer', 'min:0', 'max:60'],
            'city_id'        => ['nullable', 'exists:cities,id'],
            'state_id'       => ['nullable', 'exists:states,id'],
            'pincode'        => ['nullable', 'string', 'max:10'],
            'lead_source_id' => ['nullable', 'exists:lead_sources,id'],
            'campaign_id'    => ['nullable', 'exists:campaigns,id'],
            'adset_id'       => ['nullable', 'exists:adsets,id'],
            'ad_id'          => ['nullable', 'exists:ads,id'],
            'center_id'      => ['nullable', 'exists:centers,id'],
            'assigned_to'    => ['nullable', 'exists:users,id'],
            'utm_source'     => ['nullable', 'string', 'max:191'],
            'utm_medium'     => ['nullable', 'string', 'max:191'],
            'utm_campaign'   => ['nullable', 'string', 'max:191'],
            'utm_term'       => ['nullable', 'string', 'max:191'],
            'utm_content'    => ['nullable', 'string', 'max:191'],
            'gclid'          => ['nullable', 'string', 'max:255'],
            'fbclid'         => ['nullable', 'string', 'max:255'],
            'external_lead_id' => ['nullable', 'string', 'max:191'],
            'source_name'    => ['nullable', 'string', 'max:191'],
            'lead_date'      => ['nullable', 'date'],
            'remark'         => ['nullable', 'string', 'max:5000'],
            'extra'          => ['nullable', 'array'],
        ]);

        // Stamp who submitted it, for attribution, unless a specific channel
        // name was given by the caller.
        $data['source_name'] = $data['source_name'] ?? ('API: ' . ($request->user()?->name ?? 'token'));

        $existingBefore = ! empty($data['mobile'])
            ? Lead::where('dedup_hash', \App\Support\PhoneNumber::dedupHash($data['mobile']))->exists()
            : false;

        $lead = $this->service->create($data);

        return response()->json([
            'success'    => true,
            'deduped'    => $existingBefore,
            'message'    => $existingBefore
                ? 'An existing lead with this number was found; the enquiry was recorded against it.'
                : 'Lead created.',
            'data'       => $this->payload($lead),
        ], $existingBefore ? 200 : 201);
    }

    /**
     * GET /api/v1/leads — list leads, newest first, with light filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'mobile'    => ['nullable', 'string', 'max:20'],
            'status'    => ['nullable', 'string', 'max:30'],
            'center_id' => ['nullable', 'exists:centers,id'],
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Lead::query()
            ->with(['source:id,name', 'campaign:id,name', 'assignedTo:id,name', 'center:id,name,code', 'stage:id,name', 'disposition:id,name'])
            ->whereNull('merged_into_id')
            ->when(! empty($filters['mobile']), fn ($q) => $q->where('dedup_hash', \App\Support\PhoneNumber::dedupHash($filters['mobile'])))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['center_id']), fn ($q) => $q->where('center_id', $filters['center_id']))
            ->when(! empty($filters['from']), fn ($q) => $q->where('created_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->where('created_at', '<=', $filters['to']))
            ->latest('id');

        $page = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'success' => true,
            'data'    => collect($page->items())->map(fn (Lead $lead) => $this->payload($lead)),
            'meta'    => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    public function show(Lead $lead): JsonResponse
    {
        $lead->load(['source', 'campaign', 'stage', 'disposition', 'assignedTo:id,name', 'center:id,name,code', 'sourceTouches']);

        return response()->json(['success' => true, 'data' => $this->payload($lead, detailed: true)]);
    }

    public function updateDisposition(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'disposition_id'     => ['required', 'exists:dispositions,id'],
            'sub_disposition_id' => ['nullable', 'exists:dispositions,id'],
            'remark'             => ['required', 'string'],
            'next_follow_up_at'  => ['nullable', 'date'],
        ]);

        $this->service->updateDisposition(
            $lead,
            $data['disposition_id'],
            $data['sub_disposition_id'] ?? null,
            $data['remark'],
            $data['next_follow_up_at'] ?? null,
        );

        return response()->json(['success' => true, 'data' => $this->payload($lead->fresh())]);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Lead $lead, bool $detailed = false): array
    {
        $base = [
            'id'            => $lead->id,
            'uuid'          => $lead->uuid,
            'name'          => $lead->name,
            'mobile'        => $lead->mobile,
            'alt_mobile'    => $lead->alt_mobile,
            'email'         => $lead->email,
            'status'        => $lead->status,
            'channel'       => $lead->channel,
            'source'        => $lead->source?->name,
            'campaign'      => $lead->campaign?->name,
            'center'        => $lead->center?->code,
            'stage'         => $lead->stage?->name,
            'disposition'   => $lead->disposition?->name,
            'assigned_to'   => $lead->assignedTo?->name,
            'assigned_at'   => $lead->assigned_at?->toIso8601String(),
            'created_at'    => $lead->created_at?->toIso8601String(),
        ];

        if ($detailed) {
            $base['touches'] = $lead->sourceTouches->map(fn ($t) => [
                'channel'     => $t->channel,
                'utm_source'  => $t->utm_source,
                'utm_campaign'=> $t->utm_campaign,
                'is_first'    => $t->is_first,
                'occurred_at' => $t->occurred_at?->toIso8601String(),
            ])->all();
        }

        return $base;
    }
}
