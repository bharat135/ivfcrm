<?php

namespace App\Services\Meta\Flows;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Message;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowResponse;
use App\Models\WhatsappFlowSession;
use App\Services\LeadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a completed Flow into CRM data.
 *
 * WhatsApp delivers a completion as an inbound message of type `interactive`
 * with `interactive.type = "nfm_reply"`, whose `response_json` is a *string*
 * of the collected answers. It carries the flow_token, which is the only way
 * back to the lead the flow was sent to.
 *
 * Raw answers are stored verbatim before any mapping runs, so a submission
 * survives a bad field map and can be replayed once the map is fixed.
 */
class FlowResponseProcessor
{
    public function __construct(private LeadService $leads) {}

    /**
     * @param  array  $nfmReply  the `interactive.nfm_reply` node
     */
    public function record(
        array $nfmReply,
        ?Conversation $conversation,
        ?Message $message = null,
    ): ?WhatsappFlowResponse {
        $answers = $this->decodeAnswers($nfmReply['response_json'] ?? null);

        if ($answers === null) {
            Log::warning('WhatsApp flow reply had no readable response_json');

            return null;
        }

        // Meta echoes the token inside the response payload.
        $token = $answers['flow_token'] ?? null;

        $session = $token
            ? WhatsappFlowSession::with('flow')->where('flow_token', $token)->first()
            : null;

        $flow = $session?->flow ?? $this->guessFlow($conversation);

        if (! $flow) {
            Log::warning('WhatsApp flow reply could not be matched to a flow', ['token' => $token]);

            return null;
        }

        // Meta retries webhook batches; one completion, one response row.
        if ($message?->id) {
            $existing = WhatsappFlowResponse::where('message_id', $message->id)->first();

            if ($existing) {
                return $existing;
            }
        }

        $response = WhatsappFlowResponse::create([
            'whatsapp_flow_id'         => $flow->id,
            'whatsapp_flow_session_id' => $session?->id,
            'conversation_id'          => $conversation?->id,
            'lead_id'                  => $session?->lead_id ?? $conversation?->lead_id,
            'patient_id'               => $session?->patient_id ?? $conversation?->patient_id,
            'message_id'               => $message?->id,
            'flow_token'               => $token,
            'wa_id'                    => $conversation?->contact_identifier,
            'screen'                   => $answers['screen'] ?? $session?->current_screen,
            // Everything the flow collected, including anything accumulated by
            // the endpoint across earlier screens.
            'answers'                  => array_merge($session?->state ?? [], $answers),
            'status'                   => 'received',
        ]);

        $session?->update(['status' => 'completed', 'completed_at' => now()]);
        $flow->increment('response_count');

        $this->process($response);

        return $response->fresh();
    }

    /**
     * Apply the flow's field map: update or create the lead, attach the
     * appointment the endpoint booked, and write the timeline entry.
     */
    public function process(WhatsappFlowResponse $response): WhatsappFlowResponse
    {
        $flow = $response->flow;

        try {
            DB::transaction(function () use ($response, $flow) {
                $mapped = $this->mapAnswers($response->answers ?? [], $flow->effectiveFieldMap());

                $lead = $response->lead;

                if (! $lead && $flow->auto_create_lead && $response->wa_id) {
                    $lead = $this->createLead($response, $flow, $mapped);
                } elseif ($lead) {
                    $this->enrichLead($lead, $mapped);
                }

                // The endpoint books during the flow; link it to the response.
                $appointmentId = $response->answers['appointment_id']
                    ?? $response->session?->state['appointment_id']
                    ?? null;

                $response->update([
                    'lead_id'        => $lead?->id ?? $response->lead_id,
                    'appointment_id' => $appointmentId,
                    'status'         => 'processed',
                    'processed_at'   => now(),
                    'error'          => null,
                ]);

                if ($lead) {
                    $this->logToLead($lead, $flow, $response);
                }
            });
        } catch (\Throwable $e) {
            // The raw answers are already saved, so this is recoverable: fix
            // the mapping and replay.
            $response->update([
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::error('WhatsApp flow response processing failed', [
                'response_id' => $response->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $response->fresh();
    }

    // -----------------------------------------------------------------
    // Mapping
    // -----------------------------------------------------------------

    /**
     * Flow field names -> CRM attributes, dropping the flow's own internals.
     */
    public function mapAnswers(array $answers, array $fieldMap): array
    {
        $mapped = [];

        foreach ($answers as $key => $value) {
            if (in_array($key, ['flow_token', 'version', 'screen'], true)) {
                continue;
            }

            $target = $fieldMap[$key] ?? null;

            if (! $target || $value === null || $value === '') {
                continue;
            }

            // Multi-select components return arrays.
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $mapped[$target] = $value;
        }

        return $mapped;
    }

    private function createLead(WhatsappFlowResponse $response, WhatsappFlow $flow, array $mapped): ?Lead
    {
        $mobile = preg_replace('/\D/', '', (string) $response->wa_id);

        // Store Indian mobiles in the 10-digit form the rest of the CRM uses.
        if (strlen($mobile) === 12 && str_starts_with($mobile, '91')) {
            $mobile = substr($mobile, 2);
        }

        if (strlen($mobile) < 10) {
            return null;
        }

        $payload = array_filter([
            'name'           => $mapped['name'] ?? null,
            'mobile'         => $mobile,
            'email'          => $mapped['email'] ?? null,
            'age'            => isset($mapped['age']) ? (int) $mapped['age'] : null,
            'gender'         => $this->normalizeGender($mapped['gender'] ?? null),
            'pincode'        => $mapped['pincode'] ?? null,
            'remark'         => $mapped['message'] ?? null,
            'lead_source_id' => $flow->lead_source_id,
            'campaign_id'    => $flow->campaign_id,
            'center_id'      => $flow->center_id ?? ($mapped['center_id'] ?? null),
            'assigned_to'    => $flow->assign_to,
            'utm_source'     => 'whatsapp',
            'utm_medium'     => 'flow',
            'utm_campaign'   => $flow->slug,
            'lead_date'      => now(),
        ], fn ($v) => $v !== null && $v !== '');

        // Everything the flow asked, kept whether or not it is mapped.
        $payload['extra'] = [
            'whatsapp_flow'    => $flow->slug,
            'whatsapp_flow_id' => $flow->id,
            'answers'          => $response->answers,
        ];
        $payload['source_name'] = 'WhatsApp flow: ' . $flow->name;

        // LeadService applies deduplication and auto-assignment, so a repeat
        // submission enriches the existing lead instead of duplicating it.
        return $this->leads->create($payload);
    }

    /**
     * Fill gaps on an existing lead without overwriting what a human recorded.
     */
    private function enrichLead(Lead $lead, array $mapped): void
    {
        $updates = [];

        // A name taken from the sender's WhatsApp profile is provisional. What
        // the person typed into the form is what they actually call themselves,
        // so it wins — every other field still defers to what is already there.
        $provisionalName = ($lead->extra['name_source'] ?? null) === 'whatsapp_profile';

        foreach (['name', 'email', 'age', 'pincode'] as $field) {
            $overwritable = empty($lead->{$field}) || ($field === 'name' && $provisionalName);

            if ($overwritable && ! empty($mapped[$field])) {
                $updates[$field] = $field === 'age' ? (int) $mapped[$field] : $mapped[$field];
            }
        }

        if (! empty($mapped['message'])) {
            $updates['remark'] = $mapped['message'];
        }

        $updates['last_activity_at'] = now();

        // Answers that have no lead column of their own still belong somewhere.
        $extra = $lead->extra ?? [];

        if (isset($updates['name'])) {
            // The name is no longer provisional once the person has typed it.
            unset($extra['name_source']);
        }
        $extra['whatsapp_flow_answers'] = array_merge(
            $extra['whatsapp_flow_answers'] ?? [],
            array_diff_key($mapped, array_flip(['name', 'email', 'age', 'pincode', 'message'])),
        );
        $updates['extra'] = $extra;

        $lead->forceFill($updates)->save();
    }

    private function logToLead(Lead $lead, WhatsappFlow $flow, WhatsappFlowResponse $response): void
    {
        LeadActivity::create([
            'lead_id'     => $lead->id,
            'user_id'     => null,
            'type'        => 'message',
            'title'       => 'WhatsApp flow completed',
            'description' => $flow->name . ' — ' . $response->summary(),
            'properties'  => [
                'flow_id'     => $flow->id,
                'response_id' => $response->id,
                'answers'     => $response->answers,
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * `response_json` arrives as a JSON *string* inside the webhook payload.
     */
    private function decodeAnswers(mixed $responseJson): ?array
    {
        if (is_array($responseJson)) {
            return $responseJson;
        }

        if (! is_string($responseJson) || $responseJson === '') {
            return null;
        }

        $decoded = json_decode($responseJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Last resort when the token is missing: the most recent flow sent to this
     * conversation.
     */
    private function guessFlow(?Conversation $conversation): ?WhatsappFlow
    {
        if (! $conversation) {
            return null;
        }

        return WhatsappFlowSession::where('conversation_id', $conversation->id)
            ->latest('id')
            ->first()?->flow;
    }

    private function normalizeGender(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'male', 'm', 'man'     => 'male',
            'female', 'f', 'woman' => 'female',
            default                => 'other',
        };
    }
}
