<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\LeadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LeadApiController extends Controller
{
    public function __construct(private LeadService $service) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => ['nullable', 'string'],
            'mobile'         => ['required', 'string'],
            'email'          => ['nullable', 'email'],
            'lead_source_id' => ['nullable', 'exists:lead_sources,id'],
            'campaign_id'    => ['nullable', 'exists:campaigns,id'],
            'center_id'      => ['nullable', 'exists:centers,id'],
            'utm_source'     => ['nullable', 'string'],
            'utm_medium'     => ['nullable', 'string'],
            'utm_campaign'   => ['nullable', 'string'],
            'remark'         => ['nullable', 'string'],
            'extra'          => ['nullable', 'array'],
        ]);

        $lead = $this->service->create($data);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'     => $lead->id,
                'uuid'   => $lead->uuid,
                'status' => $lead->status,
            ],
        ], 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $lead->load(['source', 'stage', 'disposition', 'assignedTo:id,name']),
        ]);
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

        return response()->json(['success' => true]);
    }
}
