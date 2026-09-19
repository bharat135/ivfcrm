<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Messenger and Instagram Direct.
 *
 * Both ride the same Send API, so one class serves both; only the token and a
 * couple of capability limits differ. This is deliberately separate from
 * WhatsAppService because almost nothing else is shared — different identifiers
 * (PSID/IGSID vs phone number), a different webhook envelope, and a different
 * out-of-window story (message tags rather than approved templates).
 *
 * Authentication is a **Page access token**, not the user token. The token for
 * an Instagram thread is still the linked Page's token.
 */
class MessengerService
{
    /** Channels this service can carry. */
    public const CHANNELS = ['messenger', 'instagram'];

    /**
     * Standard messaging window. Outside it a message needs a tag, and the only
     * tag appropriate to a clinic replying to a patient is HUMAN_AGENT.
     */
    private const WINDOW_HOURS = 24;

    /** HUMAN_AGENT extends the reply window to 7 days. */
    private const HUMAN_AGENT_DAYS = 7;

    public function __construct(
        private MetaClient $client,
        private SettingsRepository $settings,
    ) {}

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    /**
     * The Page token for a thread. Falls back to the single configured Page
     * when the CRM only serves one.
     */
    public function tokenFor(?string $pageId = null, ?ChannelAccount $account = null): ?string
    {
        if ($token = $account?->credentials['page_access_token'] ?? null) {
            return $token;
        }

        $tokens = $this->settings->get('meta', 'page_tokens');

        if (is_array($tokens)) {
            if ($pageId && ! empty($tokens[$pageId])) {
                return $tokens[$pageId];
            }

            // One Page configured: no ambiguity to resolve.
            if (count($tokens) === 1) {
                return reset($tokens);
            }
        }

        // A user token can drive the Send API only if it holds the Page's
        // permissions, which is unusual but valid during development.
        return $this->client->accessToken();
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured() && (bool) $this->client->pageId();
    }

    /**
     * Which channels are actually usable, for the inbox's "start a chat" list.
     *
     * @return array<int, string>
     */
    public function availableChannels(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        return self::CHANNELS;
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    /**
     * @param  string  $recipientId  PSID (Messenger) or IGSID (Instagram)
     */
    public function sendText(
        string $recipientId,
        string $body,
        string $channel = 'messenger',
        ?string $pageId = null,
        ?ChannelAccount $account = null,
        ?string $tag = null,
    ): array {
        return $this->dispatch([
            'recipient' => ['id' => $recipientId],
            'message'   => ['text' => $body],
        ], $channel, $pageId, $account, $tag);
    }

    /**
     * Send a stored file.
     *
     * Uploaded as multipart rather than by URL: CRM media lives on a private
     * disk behind an authorised route, so Meta could not fetch a link even if
     * one were given.
     *
     * @param  string  $type  image|video|audio|file
     */
    public function sendMedia(
        string $recipientId,
        string $storedPath,
        string $disk,
        string $type,
        string $channel = 'messenger',
        ?string $pageId = null,
        ?ChannelAccount $account = null,
        ?string $tag = null,
    ): array {
        // Instagram Direct accepts images and video only.
        if ($channel === 'instagram' && ! in_array($type, ['image', 'video'], true)) {
            throw new MetaException(
                'Instagram Direct only accepts images and video. Send a link to the document instead.'
            );
        }

        $payload = [
            'recipient' => json_encode(['id' => $recipientId]),
            'message'   => json_encode([
                'attachment' => [
                    'type'    => $type,
                    'payload' => ['is_reusable' => true],
                ],
            ]),
            'messaging_type' => $tag ? 'MESSAGE_TAG' : 'RESPONSE',
        ];

        if ($tag) {
            $payload['tag'] = $tag;
        }

        return $this->client->upload(
            $this->sendEndpoint($channel, $pageId),
            Storage::disk($disk)->path($storedPath),
            'filedata',
            $payload,
            $this->tokenFor($pageId, $account),
        );
    }

    /**
     * Quick replies — up to 13 chips under the message. Useful for triage
     * ("Book a consultation" / "Ask a question") without building a whole flow.
     *
     * @param  array  $replies  [['title' => 'Book', 'payload' => 'BOOK'], ...]
     */
    public function sendQuickReplies(
        string $recipientId,
        string $body,
        array $replies,
        string $channel = 'messenger',
        ?string $pageId = null,
        ?ChannelAccount $account = null,
    ): array {
        return $this->dispatch([
            'recipient' => ['id' => $recipientId],
            'message'   => [
                'text'          => $body,
                'quick_replies' => array_map(fn ($reply) => [
                    'content_type' => 'text',
                    'title'        => mb_substr($reply['title'], 0, 20),
                    'payload'      => $reply['payload'] ?? $reply['title'],
                ], array_slice($replies, 0, 13)),
            ],
        ], $channel, $pageId, $account);
    }

    /**
     * Typing indicator / read receipt. Best-effort: never fail a send over it.
     *
     * @param  string  $action  typing_on|typing_off|mark_seen
     */
    public function senderAction(
        string $recipientId,
        string $action,
        string $channel = 'messenger',
        ?string $pageId = null,
        ?ChannelAccount $account = null,
    ): void {
        try {
            $this->client->post(
                $this->sendEndpoint($channel, $pageId),
                ['recipient' => json_encode(['id' => $recipientId]), 'sender_action' => $action],
                $this->tokenFor($pageId, $account),
            );
        } catch (\Throwable $e) {
            Log::debug('Messenger sender action failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    private function dispatch(
        array $message,
        string $channel,
        ?string $pageId,
        ?ChannelAccount $account,
        ?string $tag = null,
    ): array {
        $payload = $message + ['messaging_type' => $tag ? 'MESSAGE_TAG' : 'RESPONSE'];

        if ($tag) {
            $payload['tag'] = $tag;
        }

        // The Send API takes JSON-encoded objects as form fields.
        foreach (['recipient', 'message'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = json_encode($payload[$key]);
            }
        }

        return $this->client->post(
            $this->sendEndpoint($channel, $pageId),
            $payload,
            $this->tokenFor($pageId, $account),
        );
    }

    /**
     * Both channels post to the owning Page's messages edge; for Instagram it
     * is the linked Page, which is why the endpoint does not vary by channel.
     */
    private function sendEndpoint(string $channel, ?string $pageId): string
    {
        $id = $pageId ?: $this->client->pageId();

        if (! $id) {
            throw new MetaException(
                'No Facebook Page is configured. Add one under Settings → Integrations to use Messenger or Instagram.'
            );
        }

        return $id . '/messages';
    }

    // -----------------------------------------------------------------
    // Profiles
    // -----------------------------------------------------------------

    /**
     * Fetch what Meta will tell us about a sender. Names are not guaranteed:
     * Messenger only returns a profile if the person has interacted, and
     * Instagram returns a handle rather than a real name.
     *
     * @return array{name:?string, username:?string, avatar:?string}
     */
    public function profile(
        string $userId,
        string $channel = 'messenger',
        ?string $pageId = null,
        ?ChannelAccount $account = null,
    ): array {
        $fields = $channel === 'instagram'
            ? 'name,username,profile_pic,follower_count,is_user_follow_business'
            : 'first_name,last_name,profile_pic';

        try {
            $profile = $this->client->get($userId, ['fields' => $fields], $this->tokenFor($pageId, $account));
        } catch (\Throwable $e) {
            // A missing profile is normal and must not block the thread.
            Log::debug('Profile lookup failed', ['user' => $userId, 'error' => $e->getMessage()]);

            return ['name' => null, 'username' => null, 'avatar' => null];
        }

        $name = $profile['name']
            ?? trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));

        return [
            'name'     => $name !== '' ? $name : null,
            'username' => $profile['username'] ?? null,
            'avatar'   => $profile['profile_pic'] ?? null,
        ];
    }

    // -----------------------------------------------------------------
    // Messaging window
    // -----------------------------------------------------------------

    /**
     * What it would take to message this thread right now.
     *
     * Inside 24 hours a normal reply is allowed. Between 24 hours and 7 days a
     * HUMAN_AGENT tag is required — which exists precisely for a human
     * answering a question, so it fits a clinic replying to a patient. Beyond
     * that Meta will not deliver at all.
     *
     * @return array{allowed:bool, tag:?string, reason:?string}
     */
    public function windowState(Conversation $conversation): array
    {
        $lastInbound = $conversation->last_inbound_at;

        if (! $lastInbound) {
            return [
                'allowed' => false,
                'tag'     => null,
                'reason'  => 'This contact has not messaged you yet, so ' . $this->label($conversation->channel)
                    . ' will not deliver a message to them.',
            ];
        }

        if ($lastInbound->diffInHours(now()) < self::WINDOW_HOURS) {
            return ['allowed' => true, 'tag' => null, 'reason' => null];
        }

        if ($lastInbound->diffInDays(now()) < self::HUMAN_AGENT_DAYS) {
            // Still deliverable, but only as a human agent follow-up.
            return ['allowed' => true, 'tag' => 'HUMAN_AGENT', 'reason' => null];
        }

        return [
            'allowed' => false,
            'tag'     => null,
            'reason'  => 'It has been more than ' . self::HUMAN_AGENT_DAYS . ' days since this contact last wrote, so '
                . $this->label($conversation->channel) . ' no longer allows a reply. Reach them by phone or WhatsApp.',
        ];
    }

    private function label(string $channel): string
    {
        return $channel === 'instagram' ? 'Instagram' : 'Messenger';
    }
}
