<?php

namespace App\Services\Meta\Flows;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Patient;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use App\Services\Chat\ConversationService;
use App\Services\Meta\MetaException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sending a Flow to a contact.
 *
 * A Flow message is an interactive message, so it obeys the same 24-hour
 * customer-service window as any free-form send. The session row is created
 * before the Graph call so a failed send still leaves a trace.
 */
class FlowDispatcher
{
    public function __construct(
        private WhatsAppFlowService $flows,
        private ConversationService $conversations,
    ) {}

    /**
     * @param  array  $options  cta, body, header, footer, screen, data
     */
    public function send(
        WhatsappFlow $flow,
        Conversation $conversation,
        array $options = [],
        ?int $userId = null,
    ): Message {
        if (! $flow->canSend()) {
            throw new MetaException($flow->blockReason() ?? 'This flow cannot be sent.');
        }

        if (! $conversation->isWindowOpen()) {
            throw new MetaException(
                'The 24-hour WhatsApp window for this contact has closed. Send an approved template first, then the flow.'
            );
        }

        if ($flow->isEndpointFlow() && ! $flow->endpoint_uri) {
            throw new MetaException('This flow expects an endpoint but none is configured.');
        }

        $token = WhatsAppFlowService::generateFlowToken();

        return DB::transaction(function () use ($flow, $conversation, $options, $userId, $token) {
            // Logged as a normal outbound message so it appears in the thread
            // and on the lead timeline like everything else.
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'user_id'         => $userId ?? Auth::id(),
                'direction'       => 'outbound',
                'type'            => 'interactive',
                'media_type'      => 'flow',
                'body'            => $options['body'] ?? $flow->name,
                'status'          => 'queued',
                'meta'            => ['flow_id' => $flow->id, 'flow_token' => $token],
            ]);

            $session = WhatsappFlowSession::create([
                'whatsapp_flow_id' => $flow->id,
                'conversation_id'  => $conversation->id,
                'lead_id'          => $conversation->lead_id,
                'patient_id'       => $conversation->patient_id,
                'message_id'       => $message->id,
                'sent_by'          => $userId ?? Auth::id(),
                'flow_token'       => $token,
                'wa_id'            => $conversation->contact_identifier,
                'status'           => 'sent',
                'sent_at'          => now(),
                'expires_at'       => now()->addDays((int) config('whatsapp_flows.session_ttl_days')),
            ]);

            try {
                $response = $this->flows->sendFlow(
                    to: $conversation->contact_identifier,
                    flow: $flow,
                    flowToken: $token,
                    options: $options,
                    account: $conversation->channelAccount,
                );
            } catch (MetaException $e) {
                $message->update([
                    'status'        => 'failed',
                    'error_code'    => (string) $e->errorCode,
                    'error_message' => $e->displayMessage(),
                    'failed_at'     => now(),
                ]);
                $session->update(['status' => 'failed']);

                Log::warning('WhatsApp flow send failed', [
                    'flow_id' => $flow->id,
                    'error'   => $e->getMessage(),
                ]);

                throw $e;
            }

            $message->update([
                'provider_message_id' => $response['messages'][0]['id'] ?? null,
                'status'              => 'sent',
                'sent_at'             => now(),
                'meta'                => array_merge($message->meta ?? [], ['response' => $response]),
            ]);

            $conversation->update(['last_message_at' => now(), 'unread_count' => 0]);
            $flow->increment('sent_count');

            $this->logToLead($flow, $conversation, $message);

            return $message->fresh();
        });
    }

    /**
     * Convenience for the lead/patient screens: resolve (or open) the thread,
     * then send.
     */
    public function sendToContact(
        WhatsappFlow $flow,
        ?Lead $lead = null,
        ?Patient $patient = null,
        ?string $mobile = null,
        array $options = [],
        ?int $userId = null,
    ): Message {
        $number = $mobile ?: $lead?->mobile ?: $patient?->mobile;

        if (! $number) {
            throw new MetaException('No WhatsApp number available for this contact.');
        }

        $conversation = $this->conversations->resolve(
            phone: $number,
            channel: 'whatsapp',
            contactName: $lead?->name ?: $patient?->name,
            lead: $lead,
            patient: $patient,
        );

        return $this->send($flow, $conversation, $options, $userId);
    }

    private function logToLead(WhatsappFlow $flow, Conversation $conversation, Message $message): void
    {
        if (! $conversation->lead_id) {
            return;
        }

        \App\Models\LeadActivity::create([
            'lead_id'     => $conversation->lead_id,
            'user_id'     => $message->user_id,
            'type'        => 'message',
            'title'       => 'WhatsApp flow sent',
            'description' => $flow->name,
            'properties'  => [
                'flow_id'    => $flow->id,
                'message_id' => $message->id,
            ],
        ]);
    }
}
