<?php

namespace App\Services\Meta;

use App\Jobs\DownloadChannelMediaJob;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Chat\ConversationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Messenger and Instagram Direct webhooks.
 *
 * These arrive in a different envelope from WhatsApp: `entry[].messaging[]`
 * rather than `entry[].changes[]`, with a flat event object per item. The
 * `object` on the payload says which channel — "page" for Messenger,
 * "instagram" for Instagram Direct.
 *
 * Two details that are easy to get wrong and expensive to miss:
 *
 *  - **Echoes.** Meta replays messages the Page itself sent (from this CRM,
 *    from Meta Business Suite, from anywhere). On an echo the `sender` is the
 *    Page and the `recipient` is the customer — the opposite of an inbound
 *    message. Treating one as inbound would credit the customer with the
 *    clinic's own replies and inflate every response-time metric.
 *
 *  - **Referrals.** A click-to-Messenger ad carries the ad and campaign ids on
 *    the first message. That is the only moment the attribution exists, so it
 *    is captured on the thread rather than looked up later.
 */
class SocialMessagingHandler
{
    public function __construct(
        private ConversationService $conversations,
        private MessengerService $messenger,
    ) {}

    /**
     * @param  string  $object  'page' (Messenger) or 'instagram'
     */
    public function handleEntry(array $entry, string $object): void
    {
        $channel = $object === 'instagram' ? 'instagram' : 'messenger';
        $pageId  = (string) ($entry['id'] ?? '');

        foreach ($entry['messaging'] ?? [] as $event) {
            try {
                $this->handleEvent($event, $channel, $pageId);
            } catch (\Throwable $e) {
                Log::error('Social messaging event failed', [
                    'channel' => $channel,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    private function handleEvent(array $event, string $channel, string $pageId): void
    {
        // Delivery and read receipts carry no message body.
        if (isset($event['delivery'])) {
            $this->markDelivered($event['delivery']);

            return;
        }

        if (isset($event['read'])) {
            $this->markRead($event['read'], $event['sender']['id'] ?? null, $channel);

            return;
        }

        if (isset($event['reaction'])) {
            $this->recordReaction($event, $channel, $pageId);

            return;
        }

        if (isset($event['postback'])) {
            $this->recordPostback($event, $channel, $pageId);

            return;
        }

        if (isset($event['message'])) {
            $this->recordMessage($event, $channel, $pageId);

            return;
        }

        // A referral with no message: the person opened the thread from an ad
        // but has not written yet.
        if (isset($event['referral'])) {
            $conversation = $this->conversationFor($event['sender']['id'] ?? null, $channel, $pageId);
            $this->applyReferral($conversation, $event['referral']);
        }
    }

    // -----------------------------------------------------------------
    // Messages
    // -----------------------------------------------------------------

    private function recordMessage(array $event, string $channel, string $pageId): void
    {
        $message = $event['message'];
        $isEcho  = (bool) ($message['is_echo'] ?? false);

        // On an echo the Page is the sender, so the customer is the recipient.
        $contactId = $isEcho
            ? ($event['recipient']['id'] ?? null)
            : ($event['sender']['id'] ?? null);

        if (! $contactId) {
            return;
        }

        $conversation = $this->conversationFor($contactId, $channel, $pageId);

        if (! $conversation) {
            return;
        }

        if (! empty($event['referral'])) {
            $this->applyReferral($conversation, $event['referral']);
        }

        $mid = $message['mid'] ?? null;

        // Meta retries the whole batch until it gets a 200.
        if ($mid && Message::where('provider_message_id', $mid)->exists()) {
            return;
        }

        [$body, $mediaType, $meta] = $this->parseContent($message, $channel);

        $timestamp = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs((int) $event['timestamp'])->setTimezone(config('app.timezone'))
            : now();

        if ($isEcho) {
            $this->recordEcho($conversation, $message, $mid, $body, $mediaType, $meta, $timestamp);
        } else {
            $stored = $this->conversations->recordInbound($conversation, [
                'type'                => $mediaType ? 'media' : 'text',
                'media_type'          => $mediaType,
                'body'                => $body,
                'provider_message_id' => $mid,
                'reply_to_message_id' => $message['reply_to']['mid'] ?? null,
                'meta'                => $meta,
            ]);

            $this->attachMedia($stored, $message);
            $this->enrichProfile($conversation, $channel);
        }
    }

    /**
     * A message the Page sent elsewhere. Stored as outbound so the thread reads
     * correctly, but flagged so it is never counted as a customer reply and
     * never reopens the 24-hour window.
     */
    private function recordEcho(
        Conversation $conversation,
        array $message,
        ?string $mid,
        ?string $body,
        ?string $mediaType,
        array $meta,
        Carbon $timestamp,
    ): void {
        $stored = Message::create([
            'conversation_id'     => $conversation->id,
            'direction'           => 'outbound',
            'is_echo'             => true,
            'type'                => $mediaType ? 'media' : 'text',
            'media_type'          => $mediaType,
            'body'                => $body,
            'provider_message_id' => $mid,
            'status'              => 'sent',
            'sent_at'             => $timestamp,
            'meta'                => $meta + ['echo' => true],
        ]);

        $this->attachMedia($stored, $message);

        // An echo is still activity on the thread, but not an inbound one.
        $conversation->update(['last_message_at' => $timestamp]);
    }

    /**
     * Flatten the per-type payload into a body string and a media type.
     *
     * @return array{0:?string, 1:?string, 2:array}
     */
    private function parseContent(array $message, string $channel): array
    {
        $text = $message['text'] ?? null;
        $meta = [];

        // An Instagram story reply or mention arrives as an attachment plus a
        // reply_to.story, and reads as nonsense without the context.
        if (! empty($message['reply_to']['story'])) {
            $meta['story'] = $message['reply_to']['story'];
            $text = $text ? 'Replied to your story: ' . $text : 'Replied to your story';
        }

        $attachments = $message['attachments'] ?? [];

        if ($attachments === []) {
            return [$text, null, $meta];
        }

        $first = $attachments[0];
        $type  = $first['type'] ?? 'file';

        $meta['attachments'] = $attachments;

        // `share` and `template` are link previews and structured cards, not
        // files — there is nothing to download.
        if (in_array($type, ['share', 'template', 'fallback'], true)) {
            $url = $first['payload']['url'] ?? null;

            return [$text ?: ($url ? 'Shared a link: ' . $url : 'Shared an attachment'), null, $meta];
        }

        if ($type === 'story_mention') {
            return [$text ?: 'Mentioned you in a story', 'image', $meta];
        }

        $mediaType = match ($type) {
            'image', 'video', 'audio' => $type,
            'file'                    => 'document',
            default                   => 'document',
        };

        return [$text, $mediaType, $meta];
    }

    /**
     * Messenger and Instagram give a CDN URL rather than a media id, and it
     * expires quickly — so the download is queued straight away.
     */
    private function attachMedia(Message $stored, array $message): void
    {
        if ($stored->messageAttachments()->exists()) {
            return;
        }

        foreach ($message['attachments'] ?? [] as $attachment) {
            $type = $attachment['type'] ?? null;
            $url  = $attachment['payload']['url'] ?? null;

            if (! $url || in_array($type, ['share', 'template', 'fallback'], true)) {
                continue;
            }

            $record = MessageAttachment::create([
                'message_id'      => $stored->id,
                'path'            => '',
                'disk'            => config('meta.whatsapp.media_disk'),
                'source_url'      => $url,
                'type'            => match ($type) {
                    'image', 'story_mention' => 'image',
                    'video' => 'video',
                    'audio' => 'audio',
                    default => 'document',
                },
                'download_status' => 'pending',
            ]);

            DownloadChannelMediaJob::dispatch($record->id);
        }
    }

    // -----------------------------------------------------------------
    // Non-message events
    // -----------------------------------------------------------------

    private function recordPostback(array $event, string $channel, string $pageId): void
    {
        $conversation = $this->conversationFor($event['sender']['id'] ?? null, $channel, $pageId);

        if (! $conversation) {
            return;
        }

        $postback = $event['postback'];
        $mid = $postback['mid'] ?? null;

        if ($mid && Message::where('provider_message_id', $mid)->exists()) {
            return;
        }

        if (! empty($event['postback']['referral'])) {
            $this->applyReferral($conversation, $event['postback']['referral']);
        }

        $this->conversations->recordInbound($conversation, [
            'type'                => 'text',
            'body'                => $postback['title'] ?? 'Tapped a button',
            'provider_message_id' => $mid,
            'meta'                => ['postback' => $postback],
        ]);
    }

    private function recordReaction(array $event, string $channel, string $pageId): void
    {
        $conversation = $this->conversationFor($event['sender']['id'] ?? null, $channel, $pageId);

        if (! $conversation) {
            return;
        }

        $reaction = $event['reaction'];

        // A removed reaction is not worth a thread entry.
        if (($reaction['action'] ?? null) === 'unreact') {
            return;
        }

        $this->conversations->recordInbound($conversation, [
            'type' => 'text',
            'body' => ($reaction['emoji'] ?? '👍') . ' reacted to your message',
            'meta' => ['reaction' => $reaction],
        ]);
    }

    private function markDelivered(array $delivery): void
    {
        foreach ($delivery['mids'] ?? [] as $mid) {
            $this->conversations->updateStatus($mid, 'delivered');
        }
    }

    /**
     * A read receipt gives a watermark timestamp rather than message ids, so
     * everything sent up to that point is marked read at once.
     */
    private function markRead(array $read, ?string $contactId, string $channel): void
    {
        if (! $contactId || empty($read['watermark'])) {
            return;
        }

        $conversation = Conversation::where('channel', $channel)
            ->where('contact_identifier', $contactId)
            ->first();

        if (! $conversation) {
            return;
        }

        $watermark = Carbon::createFromTimestampMs((int) $read['watermark'])->setTimezone(config('app.timezone'));

        $conversation->messages()
            ->where('direction', 'outbound')
            ->whereIn('status', ['sent', 'delivered'])
            ->where('created_at', '<=', $watermark)
            ->update(['status' => 'read', 'read_at' => $watermark]);
    }

    /**
     * Click-to-Messenger / click-to-Instagram ad attribution. This is the only
     * event that carries it, so it is stored on the thread immediately.
     */
    private function applyReferral(?Conversation $conversation, array $referral): void
    {
        if (! $conversation) {
            return;
        }

        $meta = $conversation->meta ?? [];
        $meta['referral'] = $referral;

        $updates = ['meta' => $meta];

        // An ad-sourced thread should be attributed like any other ad lead.
        if (! empty($referral['ad_id'])) {
            $ad = \App\Models\Ad::where('external_id', $referral['ad_id'])->first();

            if ($ad) {
                $meta['ad_id']     = $ad->id;
                $meta['adset_id']  = $ad->adset_id;
                $updates['meta']   = $meta;
            }
        }

        $conversation->update($updates);
    }

    // -----------------------------------------------------------------
    // Threads and profiles
    // -----------------------------------------------------------------

    private function conversationFor(?string $contactId, string $channel, string $pageId): ?Conversation
    {
        if (! $contactId) {
            return null;
        }

        $conversation = $this->conversations->resolve(
            phone: $contactId,
            channel: $channel,
            account: $this->accountFor($channel, $pageId),
        );

        // A PSID is only unique within a Page, so the owning Page is recorded.
        if (! $conversation->page_id && $pageId) {
            $conversation->update(['page_id' => $pageId]);
        }

        return $conversation;
    }

    /**
     * Fill in the contact's name and avatar once. Meta does not send them with
     * the message, and a thread labelled by a raw PSID is unusable for an agent.
     */
    private function enrichProfile(Conversation $conversation, string $channel): void
    {
        if ($conversation->contact_name) {
            return;
        }

        $profile = $this->messenger->profile(
            $conversation->contact_identifier,
            $channel,
            $conversation->page_id,
            $conversation->channelAccount,
        );

        if (! $profile['name'] && ! $profile['username']) {
            return;
        }

        $conversation->update(array_filter([
            'contact_name'     => $profile['name'] ?: ($profile['username'] ? '@' . $profile['username'] : null),
            'contact_username' => $profile['username'],
            'avatar_url'       => $profile['avatar'],
        ]));
    }

    private function accountFor(string $channel, string $pageId): ?ChannelAccount
    {
        return ChannelAccount::where('channel', $channel)
            ->where('is_active', true)
            ->get()
            ->first(fn (ChannelAccount $a) => ($a->credentials['page_id'] ?? null) === $pageId)
            ?? $this->conversations->defaultAccount($channel);
    }
}
