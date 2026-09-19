<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadSource;
use App\Models\WebhookEvent;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private LeadService $service) {}

    public function facebook(Request $request): JsonResponse
    {
        // Hub verification
        if ($request->get('hub_mode') === 'subscribe') {
            $token = $request->get('hub_verify_token');
            if ($token === config('services.facebook.verify_token')) {
                return response()->json((int) $request->get('hub_challenge'));
            }
            abort(403);
        }

        $payload = $request->all();
        WebhookEvent::create(['source' => 'facebook', 'payload' => $payload, 'status' => 'received']);

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if ($change['field'] !== 'leadgen') continue;

                $leadData = $change['value'] ?? [];
                $source = LeadSource::where('slug', 'meta')->orWhere('channel', 'meta')->first();

                $fields = [];
                foreach ($leadData['field_data'] ?? [] as $field) {
                    $fields[$field['name']] = $field['values'][0] ?? null;
                }

                try {
                    $this->service->create([
                        'name'             => $fields['full_name'] ?? null,
                        'mobile'           => $fields['phone_number'] ?? null,
                        'email'            => $fields['email'] ?? null,
                        'lead_source_id'   => $source?->id,
                        'external_lead_id' => $leadData['leadgen_id'] ?? null,
                        'extra'            => $fields,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Facebook webhook lead create failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function generic(Request $request, string $source): JsonResponse
    {
        $payload = $request->all();
        WebhookEvent::create(['source' => $source, 'payload' => $payload, 'status' => 'received']);

        $leadSource = LeadSource::where('slug', $source)->first();

        try {
            $this->service->create([
                'name'           => $payload['name'] ?? null,
                'mobile'         => $payload['mobile'] ?? $payload['phone'] ?? null,
                'email'          => $payload['email'] ?? null,
                'lead_source_id' => $leadSource?->id,
                'extra'          => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::error("Generic webhook [{$source}] lead create failed", ['error' => $e->getMessage()]);
        }

        return response()->json(['status' => 'ok']);
    }
}
