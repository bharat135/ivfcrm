<?php

namespace App\Services\Meta;

use App\Jobs\DownloadChannelMediaJob;
use App\Jobs\ImportMetaLeadJob;
use App\Models\ChannelAccount;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\WebhookEvent;
use App\Services\Chat\ConversationService;
use Illuminate\Support\Facades\Log;

/**
 * Turns a raw Meta webhook payload into CRM records.
 *
 * Meta batches several entries/changes into one POST and retries the whole
 * batch if it does not get a 200 quickly, so every handler here is idempotent
 * and any slow work (media download, lead enrichment) is queued.
 */
class MetaWebhookProcessor
{
    public function __construct(
        private ConversationService $conversations,
        private \App\Services\Telephony\WhatsAppCallHandler $calls,
        private \App\Services\Meta\Flows\FlowResponseProcessor $flowResponses,
        private SocialMessagingHandler $social,
        private ClickToWhatsAppAttributor $ctwa,
    ) {}

    public function handle(array $payload): void
    {
        $object = $payload['object'] ?? null;

        foreach ($payload['entry'] ?? [] as $entry) {
            // Messenger and Instagram use a different envelope: a flat
            // `messaging[]` array instead of `changes[]`. Same endpoint, same
            // signature check, completely different shape.
            if (isset($entry['messaging'])) {
                try {
                    $this->social->handleEntry($entry, (string) $object);
                } catch (\Throwable $e) {
                    Log::error('Social messaging entry failed', [
                        'object' => $object,
                        'error'  => $e->getMessage(),
                    ]);
                }

                continue;
            }

            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];

                try {
                    match ($field) {
                        'messages'                 => $this->handleMessages($value),
                        'calls'                    => $this->handleCalls($value),
                        'leadgen'                  => $this->handleLeadgen($value, $entry),
                        'message_template_status_update' => $this->handleTemplateStatus($value),
                        default                    => Log::debug('Unhandled Meta webhook field', [
                            'object' => $object,
                            'field'  => $field,
                        ]),
                    };
                } catch (\Throwable $e) {
                    Log::error('Meta webhook change failed', [
                        'field' => $field,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // WhatsApp messages + statuses
    // -----------------------------------------------------------------

    private function handleMessages(array $value): void
    {
        $account = $this->resolveAccount($value['metadata']['phone_number_id'] ?? null);

        // Contacts arrive alongside messages and carry the WhatsApp profile name.
        $names = [];
        foreach ($value['contacts'] ?? [] as $contact) {
            $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? null;
        }

        foreach ($value['messages'] ?? [] as $message) {
            $this->storeInbound($message, $names, $account);
        }

        foreach ($value['statuses'] ?? [] as $status) {
            $this->conversations->updateStatus(
                $status['id'] ?? '',
                $status['status'] ?? 'sent',
                $status['errors'] ?? null,
                isset($status['timestamp']) ? (int) $status['timestamp'] : null,
            );
        }

        foreach ($value['errors'] ?? [] as $error) {
            Log::warning('WhatsApp webhook reported an account error', $error);
        }
    }

    private function storeInbound(array $message, array $names, ?ChannelAccount $account): void
    {
        $from = $message['from'] ?? null;
        if (! $from) {
            return;
        }

        $conversation = $this->conversations->resolve(
            phone: $from,
            channel: 'whatsapp',
            account: $account,
            contactName: $names[$from] ?? null,
        );

        $type = $message['type'] ?? 'text';

        [$body, $mediaType, $meta] = $this->parseContent($message, $type);

        // A click-to-WhatsApp ad leaves its only trace on this first message,
        // so the enquiry (and therefore the patient file) is opened before the
        // message is stored — the thread is then already attributed.
        $this->ctwa->attribute($conversation, $message, $names[$from] ?? null);

        $conversation->refresh();

        $stored = $this->conversations->recordInbound($conversation, [
            'type'                => $mediaType ? 'media' : 'text',
            'media_type'          => $mediaType,
            'body'                => $body,
            'provider_message_id' => $message['id'] ?? null,
            'reply_to_message_id' => $message['context']['id'] ?? null,
            'meta'                => $meta,
        ]);

        // A completed Flow rides in on an ordinary inbound message. Recording
        // it here keeps the thread and the submission in one transaction path.
        if ($type === 'interactive' && ($message['interactive']['type'] ?? null) === 'nfm_reply') {
            $this->flowResponses->record(
                $message['interactive']['nfm_reply'] ?? [],
                $conversation,
                $stored,
            );
        }

        // A media message references a media id; the binary is fetched async.
        $mediaId = $message[$type]['id'] ?? null;

        if ($mediaType && $mediaId && $stored->messageAttachments()->count() === 0) {
            $attachment = MessageAttachment::create([
                'message_id'        => $stored->id,
                'path'              => '',
                'disk'              => config('meta.whatsapp.media_disk'),
                'provider_media_id' => $mediaId,
                'mime'              => $message[$type]['mime_type'] ?? null,
                'type'              => $mediaType,
                'filename'          => $message[$type]['filename'] ?? null,
                'caption'           => $message[$type]['caption'] ?? null,
                'sha256'            => $message[$type]['sha256'] ?? null,
                'download_status'   => 'pending',
            ]);

            DownloadChannelMediaJob::dispatch($attachment->id);
        }
    }

    /**
     * Flatten WhatsApp's per-type payload into a body string plus a media type.
     *
     * @return array{0:?string, 1:?string, 2:array}
     */
    private function parseContent(array $message, string $type): array
    {
        return match ($type) {
            'text' => [$message['text']['body'] ?? null, null, []],

            'image', 'video', 'audio', 'document', 'sticker' => [
                $message[$type]['caption'] ?? null,
                $type,
                [],
            ],

            'location' => [
                trim(($message['location']['name'] ?? '') . ' ' . ($message['location']['address'] ?? '')) ?: 'Shared a location',
                'location',
                ['location' => $message['location'] ?? []],
            ],

            'contacts' => [
                'Shared ' . count($message['contacts'] ?? []) . ' contact(s)',
                'contacts',
                ['contacts' => $message['contacts'] ?? []],
            ],

            // Quick-reply button tap on a template.
            'button' => [
                $message['button']['text'] ?? 'Button reply',
                null,
                ['button' => $message['button'] ?? []],
            ],

            // Reply to an interactive message: a button, a list, or a
            // completed Flow (nfm_reply, "native flow message").
            'interactive' => [
                ($message['interactive']['type'] ?? null) === 'nfm_reply'
                    ? ($message['interactive']['nfm_reply']['body'] ?? 'Flow completed')
                    : ($message['interactive']['button_reply']['title']
                        ?? $message['interactive']['list_reply']['title']
                        ?? 'Interactive reply'),
                null,
                ['interactive' => $message['interactive'] ?? []],
            ],

            'reaction' => [
                ($message['reaction']['emoji'] ?? '👍') . ' reacted',
                null,
                ['reaction' => $message['reaction'] ?? []],
            ],

            // Meta sends type=unsupported for message kinds the Cloud API cannot relay.
            'unsupported' => ['[Unsupported message type]', null, ['raw' => $message]],

            default => [$message['text']['body'] ?? '[' . $type . ']', null, ['raw' => $message]],
        };
    }

    private function resolveAccount(?string $phoneNumberId): ?ChannelAccount
    {
        if (! $phoneNumberId) {
            return $this->conversations->defaultAccount('whatsapp');
        }

        $account = ChannelAccount::where('channel', 'whatsapp')
            ->get()
            ->first(fn (ChannelAccount $a) => ($a->credentials['phone_number_id'] ?? null) === $phoneNumberId);

        return $account ?: $this->conversations->defaultAccount('whatsapp');
    }

    // -----------------------------------------------------------------
    // WhatsApp calling
    // -----------------------------------------------------------------

    /**
     * Voice-call lifecycle events, plus the customer's answer to a call
     * permission request (which Meta delivers on this field).
     */
    private function handleCalls(array $value): void
    {
        $account = $this->resolveAccount($value['metadata']['phone_number_id'] ?? null);

        foreach ($value['calls'] ?? [] as $call) {
            $callId = $call['id'] ?? null;

            if (! $callId) {
                continue;
            }

            $event = strtolower((string) ($call['event'] ?? 'unknown'));

            $conversation = ! empty($call['from'])
                ? $this->conversations->resolve(
                    phone: $call['from'],
                    channel: 'whatsapp',
                    account: $account,
                )
                : null;

            match ($event) {
                'connect'   => $this->calls->handleIncomingWhatsAppCall($call, $conversation, $account),
                'terminate' => $this->calls->handleWhatsAppTerminate($call),
                default     => $this->calls->recordWhatsAppEvent($call, $event),
            };
        }

        // The user's response to a call-permission request.
        foreach ($value['call_permission_updates'] ?? $value['permissions'] ?? [] as $permission) {
            $this->calls->updateWhatsAppPermission($permission);
        }
    }

    // -----------------------------------------------------------------
    // Lead Ads
    // -----------------------------------------------------------------

    /**
     * The leadgen webhook carries only ids; the answers must be fetched from
     * the Graph API, so the real work is queued.
     */
    private function handleLeadgen(array $value, array $entry): void
    {
        $leadgenId = $value['leadgen_id'] ?? null;

        if (! $leadgenId) {
            return;
        }

        ImportMetaLeadJob::dispatch(
            (string) $leadgenId,
            (string) ($value['form_id'] ?? ''),
            [
                'page_id'     => $value['page_id'] ?? ($entry['id'] ?? null),
                'ad_id'       => $value['ad_id'] ?? null,
                'adgroup_id'  => $value['adgroup_id'] ?? null,
                'campaign_id' => $value['campaign_id'] ?? null,
                'created_time'=> $value['created_time'] ?? null,
            ],
        );
    }

    // -----------------------------------------------------------------
    // Template review results
    // -----------------------------------------------------------------

    private function handleTemplateStatus(array $value): void
    {
        $name = $value['message_template_name'] ?? null;

        if (! $name) {
            return;
        }

        \App\Models\WhatsappTemplate::where('name', $name)
            ->when(
                $value['message_template_language'] ?? null,
                fn ($q, $lang) => $q->where('language', $lang),
            )
            ->update([
                'status'          => $value['event'] ?? 'PENDING',
                'rejected_reason' => $value['reason'] ?? null,
                'synced_at'       => now(),
            ]);
    }

    // -----------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------

    public function log(string $source, array $payload, ?string $eventType = null): WebhookEvent
    {
        return WebhookEvent::create([
            'source'      => $source,
            'event_type'  => $eventType ?? $this->describe($payload),
            'external_id' => $payload['entry'][0]['id'] ?? null,
            'payload'     => $payload,
            'status'      => 'received',
        ]);
    }

    private function describe(array $payload): ?string
    {
        $change = $payload['entry'][0]['changes'][0] ?? [];
        $field  = $change['field'] ?? null;

        if ($field !== 'messages') {
            return $field;
        }

        $value = $change['value'] ?? [];

        if (! empty($value['statuses'])) {
            return 'messages.status.' . ($value['statuses'][0]['status'] ?? 'unknown');
        }

        return 'messages.' . ($value['messages'][0]['type'] ?? 'unknown');
    }

    /**
     * Meta signs every webhook body with the app secret. Rejecting an unsigned
     * or mis-signed request stops anyone from injecting fake conversations.
     */
    public static function verifySignature(?string $header, string $rawBody, ?string $appSecret): bool
    {
        if (! $appSecret) {
            // No secret configured yet — allow, but the setup screen warns about it.
            return true;
        }

        if (! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $header);
    }
}
