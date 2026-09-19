<?php

namespace App\Services\Chat;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Patient;
use App\Models\WhatsappTemplate;
use App\Services\Meta\MetaException;
use App\Services\Meta\WhatsAppService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Owns everything that touches `conversations` / `messages`.
 *
 * Sending goes: controller -> ConversationService (persist queued row)
 *   -> WhatsAppService (Graph call) -> update the row with provider id/status.
 * Receiving goes: webhook -> MetaWebhookProcessor -> ConversationService.
 *
 * Persisting before the Graph call means a failed send is still visible in the
 * thread with its error, instead of vanishing.
 */
class ConversationService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private \App\Services\Meta\MessengerService $messenger,
    ) {}

    /** Channels whose identifier is an opaque page-scoped id, not a phone number. */
    private const SOCIAL_CHANNELS = ['messenger', 'instagram'];

    public function isSocial(string $channel): bool
    {
        return in_array($channel, self::SOCIAL_CHANNELS, true);
    }

    // -----------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------

    /**
     * Find (or open) the WhatsApp thread for a phone number, linking it to any
     * matching lead/patient so the agent sees full CRM context in the inbox.
     */
    public function resolve(
        string $phone,
        string $channel = 'whatsapp',
        ?ChannelAccount $account = null,
        ?string $contactName = null,
        ?Lead $lead = null,
        ?Patient $patient = null,
    ): Conversation {
        // A PSID/IGSID is opaque and must be used verbatim. Normalising it
        // would strip a leading "91" from a 12-digit id and point the thread at
        // a different person.
        $social = $this->isSocial($channel);

        $normalized = $social ? $phone : $this->whatsapp->normalizeNumber($phone);

        $conversation = Conversation::where('channel', $channel)
            ->where(function (Builder $q) use ($normalized, $phone, $social) {
                $q->where('contact_identifier', $normalized);

                if (! $social) {
                    $q->orWhere('wa_id', $normalized)->orWhere('contact_identifier', $phone);
                }
            })
            ->first();

        // Only a phone number can be matched to a CRM record on its own; a
        // social thread is linked to a lead by an agent, or by a later reply
        // that carries a number.
        $lead    = $lead    ?: ($social ? null : $this->matchLead($normalized));
        $patient = $patient ?: ($social ? null : $this->matchPatient($normalized));

        if (! $conversation) {
            $conversation = Conversation::create([
                'channel'            => $channel,
                'channel_account_id' => $account?->id ?? $this->defaultAccount($channel)?->id,
                'contact_identifier' => $normalized,
                'wa_id'              => $social ? null : $normalized,
                'contact_name'       => $contactName,
                'lead_id'            => $lead?->id,
                'patient_id'         => $patient?->id,
                'assigned_to'        => $lead?->assigned_to,
                'status'             => 'open',
            ]);

            return $conversation;
        }

        // Backfill links that were unknown when the thread was first opened.
        $updates = [];
        if (! $conversation->lead_id && $lead) {
            $updates['lead_id'] = $lead->id;
        }
        if (! $conversation->patient_id && $patient) {
            $updates['patient_id'] = $patient->id;
        }
        if (! $conversation->contact_name && $contactName) {
            $updates['contact_name'] = $contactName;
        }
        if (! $conversation->assigned_to && $lead?->assigned_to) {
            $updates['assigned_to'] = $lead->assigned_to;
        }
        if ($updates) {
            $conversation->update($updates);
        }

        return $conversation;
    }

    /**
     * Match on the last 10 digits so "+919812345678", "9812345678" and
     * "091-9812345678" all resolve to the same lead.
     */
    private function matchLead(string $normalized): ?Lead
    {
        $tail = substr($normalized, -10);

        if (strlen($tail) < 10) {
            return null;
        }

        return Lead::whereNull('merged_into_id')
            ->where(function (Builder $q) use ($tail) {
                $q->where('mobile', 'like', '%' . $tail)
                    ->orWhere('alt_mobile', 'like', '%' . $tail);
            })
            ->orderByDesc('created_at')
            ->first();
    }

    private function matchPatient(string $normalized): ?Patient
    {
        $tail = substr($normalized, -10);

        if (strlen($tail) < 10) {
            return null;
        }

        return Patient::where(function (Builder $q) use ($tail) {
            $q->where('mobile', 'like', '%' . $tail)
                ->orWhere('alt_mobile', 'like', '%' . $tail);
        })->first();
    }

    public function defaultAccount(string $channel = 'whatsapp'): ?ChannelAccount
    {
        return ChannelAccount::where('channel', $channel)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->first();
    }

    // -----------------------------------------------------------------
    // Outbound
    // -----------------------------------------------------------------

    /**
     * Free-form text. Only valid inside the 24h window.
     */
    public function sendText(Conversation $conversation, string $body, ?int $userId = null, ?string $replyTo = null): Message
    {
        $this->guardWindow($conversation);

        $message = $this->createOutbound($conversation, [
            'type'                => 'text',
            'body'                => $body,
            'reply_to_message_id' => $replyTo,
            'user_id'             => $userId ?? Auth::id(),
        ]);

        $this->deliver($message, fn () => $this->isSocial($conversation->channel)
            ? $this->messenger->sendText(
                $conversation->contact_identifier,
                $body,
                $conversation->channel,
                $conversation->page_id,
                $conversation->channelAccount,
                $this->messageTag($conversation),
            )
            : $this->whatsapp->sendText(
                $conversation->contact_identifier,
                $body,
                $conversation->channelAccount,
                $replyTo,
            ));

        return $message;
    }

    /**
     * Upload a local file to Meta, then send it and store the local copy so the
     * agent can re-view it in the thread.
     *
     * @param  string  $storedPath  Path on the WhatsApp media disk.
     */
    public function sendMedia(
        Conversation $conversation,
        string $storedPath,
        string $mime,
        string $originalName,
        ?string $caption = null,
        ?int $userId = null,
    ): Message {
        $this->guardWindow($conversation);

        $disk      = config('meta.whatsapp.media_disk');
        $mediaType = $this->whatsapp->messageTypeFor($mime);

        $message = $this->createOutbound($conversation, [
            'type'       => 'media',
            'media_type' => $mediaType,
            'body'       => $caption,
            'user_id'    => $userId ?? Auth::id(),
        ]);

        $attachment = MessageAttachment::create([
            'message_id'      => $message->id,
            'path'            => $storedPath,
            'disk'            => $disk,
            'mime'            => $mime,
            'type'            => $mediaType,
            'filename'        => $originalName,
            'caption'         => $caption,
            'size'            => Storage::disk($disk)->size($storedPath),
            'download_status' => 'downloaded',
        ]);

        $this->deliver($message, function () use ($conversation, $disk, $storedPath, $mime, $mediaType, $caption, $originalName, $attachment) {
            // Messenger and Instagram take the bytes directly; WhatsApp wants a
            // media id obtained from a separate upload first.
            if ($this->isSocial($conversation->channel)) {
                $sent = $this->messenger->sendMedia(
                    $conversation->contact_identifier,
                    $storedPath,
                    $disk,
                    $mediaType,
                    $conversation->channel,
                    $conversation->page_id,
                    $conversation->channelAccount,
                    $this->messageTag($conversation),
                );

                // A caption is not part of an attachment on these channels, so
                // it follows as its own message rather than being dropped.
                if ($caption) {
                    $this->messenger->sendText(
                        $conversation->contact_identifier,
                        $caption,
                        $conversation->channel,
                        $conversation->page_id,
                        $conversation->channelAccount,
                        $this->messageTag($conversation),
                    );
                }

                return $sent;
            }

            $mediaId = $this->whatsapp->uploadMedia(
                Storage::disk($disk)->path($storedPath),
                $mime,
                $conversation->channelAccount,
            );

            $attachment->update(['provider_media_id' => $mediaId]);

            return $this->whatsapp->sendMedia(
                $conversation->contact_identifier,
                $mediaType,
                $mediaId,
                $caption,
                $mediaType === 'document' ? $originalName : null,
                $conversation->channelAccount,
            );
        });

        return $message;
    }

    /**
     * Approved template — the only thing that sends outside the 24h window.
     */
    public function sendTemplate(
        Conversation $conversation,
        WhatsappTemplate $template,
        array $bodyParams = [],
        array $headerParam = [],
        ?int $userId = null,
    ): Message {
        if ($this->isSocial($conversation->channel)) {
            throw new MetaException(
                'Templates are a WhatsApp feature. On ' . ucfirst($conversation->channel)
                . ' a reply is allowed for 7 days after the contact writes, with no template needed.',
            );
        }

        $message = $this->createOutbound($conversation, [
            'type'          => 'template',
            'template_name' => $template->name,
            'body'          => $template->render($bodyParams),
            'user_id'       => $userId ?? Auth::id(),
            'meta'          => ['params' => $bodyParams, 'header' => $headerParam],
        ]);

        $this->deliver($message, fn () => $this->whatsapp->sendTemplate(
            $conversation->contact_identifier,
            $template->name,
            $template->language,
            $bodyParams,
            $headerParam,
            [],
            $conversation->channelAccount,
        ));

        return $message;
    }

    /**
     * Internal note — visible to staff only, never sent to the contact.
     */
    public function addNote(Conversation $conversation, string $body, ?int $userId = null): Message
    {
        $message = Message::create([
            'conversation_id'  => $conversation->id,
            'user_id'          => $userId ?? Auth::id(),
            'direction'        => 'outbound',
            'type'             => 'note',
            'body'             => $body,
            'status'           => 'sent',
            'is_internal_note' => true,
            'sent_at'          => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    private function createOutbound(Conversation $conversation, array $attributes): Message
    {
        return Message::create(array_merge([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'status'          => 'queued',
        ], $attributes));
    }

    /**
     * Run the Graph call and fold the outcome back into the message row.
     */
    private function deliver(Message $message, callable $send): void
    {
        try {
            $response = $send();

            // WhatsApp returns messages[0].id; the Send API returns message_id.
            $providerId = $response['messages'][0]['id'] ?? $response['message_id'] ?? null;

            $message->update([
                'provider_message_id' => $providerId,
                'status'              => 'sent',
                'sent_at'             => now(),
                'meta'                => array_merge($message->meta ?? [], ['response' => $response]),
            ]);

            $this->touchConversation($message->conversation, outbound: true);
            $this->logToLead($message, 'WhatsApp message sent');
        } catch (MetaException $e) {
            $message->update([
                'status'        => 'failed',
                'error_code'    => (string) $e->errorCode,
                'error_message' => $e->displayMessage(),
                'failed_at'     => now(),
            ]);

            Log::warning('WhatsApp send failed', [
                'message_id' => $message->id,
                'code'       => $e->errorCode,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $message->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at'     => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Free-form sends are rejected by Meta outside the service window; failing
     * fast here gives the agent a clear message instead of a Graph error 131047.
     */
    private function guardWindow(Conversation $conversation): void
    {
        if ($conversation->channel === 'whatsapp' && ! $conversation->isWindowOpen()) {
            throw new MetaException(
                'The 24-hour WhatsApp window for this contact has closed. Send an approved template to reopen the conversation.',
            );
        }

        // Messenger and Instagram have their own window, reopened by a message
        // tag rather than a template.
        if ($this->isSocial($conversation->channel)) {
            $state = $this->messenger->windowState($conversation);

            if (! $state['allowed']) {
                throw new MetaException($state['reason']);
            }
        }
    }

    /**
     * The message tag needed to deliver right now, or null when a plain reply
     * is allowed. Only meaningful for Messenger and Instagram.
     */
    private function messageTag(Conversation $conversation): ?string
    {
        return $this->isSocial($conversation->channel)
            ? $this->messenger->windowState($conversation)['tag']
            : null;
    }

    // -----------------------------------------------------------------
    // Inbound
    // -----------------------------------------------------------------

    /**
     * Persist an inbound WhatsApp message. Idempotent on provider_message_id,
     * because Meta retries webhook deliveries until it gets a 200.
     */
    public function recordInbound(Conversation $conversation, array $attributes, array $attachments = []): Message
    {
        return DB::transaction(function () use ($conversation, $attributes, $attachments) {
            $providerId = $attributes['provider_message_id'] ?? null;

            if ($providerId) {
                $existing = Message::where('provider_message_id', $providerId)->first();
                if ($existing) {
                    return $existing;
                }
            }

            $message = Message::create(array_merge([
                'conversation_id' => $conversation->id,
                'direction'       => 'inbound',
                'status'          => 'delivered',
                'delivered_at'    => now(),
            ], $attributes));

            foreach ($attachments as $attachment) {
                MessageAttachment::create(array_merge(['message_id' => $message->id], $attachment));
            }

            $this->touchConversation($conversation, outbound: false);
            $this->logToLead($message, 'WhatsApp message received');

            return $message;
        });
    }

    /**
     * Delivery receipt from the statuses webhook.
     */
    public function updateStatus(string $providerMessageId, string $status, ?array $errors = null, ?int $timestamp = null): ?Message
    {
        $message = Message::where('provider_message_id', $providerMessageId)->first();

        if (! $message) {
            return null;
        }

        // Never regress: a "sent" webhook arriving after "read" must not undo it.
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        if (($rank[$status] ?? 0) < ($rank[$message->status] ?? 0) && $status !== 'failed') {
            return $message;
        }

        $at = $timestamp ? now()->setTimestamp($timestamp) : now();

        $updates = ['status' => $status];

        match ($status) {
            'sent'      => $updates['sent_at'] = $message->sent_at ?: $at,
            'delivered' => $updates['delivered_at'] = $at,
            'read'      => $updates['read_at'] = $at,
            'failed'    => $updates['failed_at'] = $at,
            default     => null,
        };

        if ($status === 'failed' && $errors) {
            $error = $errors[0] ?? [];
            $updates['error_code']    = (string) ($error['code'] ?? '');
            $updates['error_message'] = $error['error_data']['details'] ?? $error['title'] ?? $error['message'] ?? 'Delivery failed';
        }

        $message->update($updates);

        // Keep broadcast counters in step with the receipts.
        $this->syncBroadcastCounters($message, $status);

        return $message;
    }

    private function syncBroadcastCounters(Message $message, string $status): void
    {
        $recipient = \App\Models\WhatsappBroadcastRecipient::where('message_id', $message->id)->first();

        if (! $recipient || $recipient->status === $status) {
            return;
        }

        $recipient->update(['status' => $status]);

        $column = match ($status) {
            'delivered' => 'delivered_count',
            'read'      => 'read_count',
            'failed'    => 'failed_count',
            default     => null,
        };

        if ($column) {
            $recipient->broadcast()->increment($column);
        }
    }

    // -----------------------------------------------------------------
    // Thread state
    // -----------------------------------------------------------------

    private function touchConversation(Conversation $conversation, bool $outbound): void
    {
        $updates = ['last_message_at' => now()];

        if ($outbound) {
            // Replying implicitly clears the unread badge.
            $updates['unread_count'] = 0;
        } else {
            $updates['last_inbound_at']   = now();
            $updates['window_expires_at'] = now()->addHours(config('meta.whatsapp.session_window_hours'));
            $updates['unread_count']      = $conversation->unread_count + 1;

            // A closed thread reopens when the contact writes back.
            if ($conversation->status === 'closed') {
                $updates['status'] = 'open';
            }
        }

        $conversation->update($updates);
    }

    public function markRead(Conversation $conversation, bool $sendReceipt = true): void
    {
        $conversation->update(['unread_count' => 0]);

        if (! $sendReceipt) {
            return;
        }

        if ($this->isSocial($conversation->channel)) {
            $this->messenger->senderAction(
                $conversation->contact_identifier,
                'mark_seen',
                $conversation->channel,
                $conversation->page_id,
                $conversation->channelAccount,
            );

            return;
        }

        if ($conversation->channel !== 'whatsapp') {
            return;
        }

        $last = $conversation->messages()
            ->where('direction', 'inbound')
            ->whereNotNull('provider_message_id')
            ->latest('id')
            ->first();

        if (! $last) {
            return;
        }

        try {
            $this->whatsapp->markAsRead($last->provider_message_id, $conversation->channelAccount);
        } catch (\Throwable $e) {
            // A read receipt is best-effort; never fail the request over it.
            Log::debug('WhatsApp read receipt failed', ['error' => $e->getMessage()]);
        }
    }

    public function assign(Conversation $conversation, ?int $userId): void
    {
        $conversation->update(['assigned_to' => $userId]);
    }

    public function setStatus(Conversation $conversation, string $status): void
    {
        $conversation->update([
            'status' => $status,
            'unread_count' => $status === 'closed' ? 0 : $conversation->unread_count,
        ]);
    }

    /** Human-readable channel name, used in timeline entries and errors. */
    public function channelLabel(string $channel): string
    {
        return match ($channel) {
            'whatsapp'  => 'WhatsApp',
            'messenger' => 'Messenger',
            'instagram' => 'Instagram',
            'sms'       => 'SMS',
            'email'     => 'Email',
            default     => ucfirst($channel),
        };
    }

    /**
     * Mirror chat traffic into the lead timeline so telecallers see one history.
     */
    private function logToLead(Message $message, string $title): void
    {
        $leadId = $message->conversation->lead_id;

        if (! $leadId) {
            return;
        }

        LeadActivity::create([
            'lead_id'     => $leadId,
            'user_id'     => $message->user_id,
            'type'        => 'message',
            'title'       => str_replace('WhatsApp', $this->channelLabel($message->conversation->channel), $title),
            'description' => $message->preview(160),
            'properties'  => [
                'channel'         => $message->conversation->channel,
                'direction'       => $message->direction,
                'message_id'      => $message->id,
                'conversation_id' => $message->conversation_id,
            ],
        ]);

        Lead::whereKey($leadId)->update(['last_activity_at' => now()]);
    }
}
