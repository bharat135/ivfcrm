<?php

namespace App\Services\Meta;

use App\Models\ChannelAccount;
use Illuminate\Support\Facades\Storage;

/**
 * WhatsApp Business Cloud API.
 *
 * Purely Graph-facing: it builds payloads, calls Meta and returns the raw
 * response. Persisting conversations/messages is the job of
 * App\Services\Chat\ConversationService, which calls into this class.
 */
class WhatsAppService
{
    public function __construct(private MetaClient $meta) {}

    /**
     * A channel account may override the shared credentials with its own
     * phone number id, WABA, token and even app id/secret (a number that
     * belongs to a different Meta app). Every Graph call for that account
     * goes through a client bound to those overrides, so the token, the
     * appsecret_proof and the ids all come from the same place.
     */
    private function client(?ChannelAccount $account = null): MetaClient
    {
        return $this->meta->forAccount($this->account($account));
    }

    /**
     * The account a call should use. A conversation, broadcast or flow with
     * no account (e.g. created before any channel account existed) goes out
     * through the active default WhatsApp sender — "Default sender for this
     * channel" — and only falls back to Settings → Integrations when there
     * is no such account.
     */
    private function account(?ChannelAccount $account): ?ChannelAccount
    {
        // A deactivated sender must not send; its old conversations move to
        // the default sender (e.g. switching from Meta to the third-party API).
        if ($account && $account->is_active) {
            return $account;
        }

        return ChannelAccount::where('channel', 'whatsapp')
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
    }

    private function phoneNumberId(?ChannelAccount $account = null): string
    {
        $id = $this->client($account)->phoneNumberId();

        if (! $id) {
            throw new MetaException('WhatsApp phone number ID is not configured. Add it under Settings → Integrations.');
        }

        return $id;
    }

    private function token(?ChannelAccount $account = null): ?string
    {
        return $this->account($account)?->credentials['access_token'] ?? null;
    }

    private function wabaId(?ChannelAccount $account = null): string
    {
        $id = $this->client($account)->wabaId();

        if (! $id) {
            throw new MetaException('WhatsApp Business Account ID (WABA ID) is not configured.');
        }

        return $id;
    }

    /**
     * Meta expects an E.164 number without the leading "+". Indian numbers are
     * commonly stored as 10 digits, so a default country code is prepended.
     */
    public function normalizeNumber(string $number, string $defaultCountryCode = '91'): string
    {
        $digits = preg_replace('/\D/', '', $number);

        if (strlen($digits) === 10) {
            $digits = $defaultCountryCode . $digits;
        }

        // 011XXXXXXXXXX style international prefixes.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    public function sendText(string $to, string $body, ?ChannelAccount $account = null, ?string $replyTo = null, bool $previewUrl = true): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizeNumber($to),
            'type'              => 'text',
            'text'              => ['preview_url' => $previewUrl, 'body' => $body],
        ];

        if ($replyTo) {
            $payload['context'] = ['message_id' => $replyTo];
        }

        return $this->dispatch($payload, $account);
    }

    /**
     * Send previously-uploaded media by its Meta media id, or by public link.
     *
     * @param  string  $type  image|video|audio|document|sticker
     */
    public function sendMedia(
        string $to,
        string $type,
        string $mediaIdOrLink,
        ?string $caption = null,
        ?string $filename = null,
        ?ChannelAccount $account = null,
        ?string $replyTo = null,
    ): array {
        $isLink = str_starts_with($mediaIdOrLink, 'http://') || str_starts_with($mediaIdOrLink, 'https://');

        $media = $isLink ? ['link' => $mediaIdOrLink] : ['id' => $mediaIdOrLink];

        // Audio and sticker do not accept a caption.
        if ($caption && in_array($type, ['image', 'video', 'document'], true)) {
            $media['caption'] = $caption;
        }
        if ($filename && $type === 'document') {
            $media['filename'] = $filename;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizeNumber($to),
            'type'              => $type,
            $type               => $media,
        ];

        if ($replyTo) {
            $payload['context'] = ['message_id' => $replyTo];
        }

        try {
            return $this->dispatch($payload, $account);
        } catch (MetaException $e) {
            // The 1automations docs name an uploaded media reference
            // `media_id` where Meta uses `id`. Try Meta's name first; if the
            // provider rejects the request, retry once with theirs. A rejected
            // request sends nothing, so this cannot deliver twice.
            if ($isLink || ! $this->client($account)->isThirdParty() || $e->isAuthError()) {
                throw $e;
            }

            $payload[$type]['media_id'] = $payload[$type]['id'];
            unset($payload[$type]['id']);

            return $this->dispatch($payload, $account);
        }
    }

    /**
     * Send an approved template. Required outside the 24h service window.
     *
     * @param  array  $bodyParams    Ordered {{1}},{{2}}... substitutions.
     * @param  array  $headerParam   ['type' => 'image', 'link' => '...'] or ['type' => 'text', 'text' => '...']
     * @param  array  $buttonParams  [['sub_type' => 'url', 'index' => 0, 'parameters' => [...]], ...]
     */
    public function sendTemplate(
        string $to,
        string $templateName,
        string $language = 'en',
        array $bodyParams = [],
        array $headerParam = [],
        array $buttonParams = [],
        ?ChannelAccount $account = null,
    ): array {
        $components = [];

        if ($headerParam !== []) {
            $components[] = [
                'type'       => 'header',
                'parameters' => [$this->headerParameter($headerParam)],
            ];
        }

        if ($bodyParams !== []) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    array_values($bodyParams),
                ),
            ];
        }

        foreach ($buttonParams as $button) {
            $components[] = array_merge(['type' => 'button'], $button);
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizeNumber($to),
            'type'              => 'template',
            'template'          => [
                'name'     => $templateName,
                'language' => ['code' => $language],
            ],
        ];

        if ($components !== []) {
            $payload['template']['components'] = $components;
        }

        return $this->dispatch($payload, $account);
    }

    /**
     * Interactive reply buttons (max 3) — handy for "Book a consultation?" flows.
     *
     * @param  array  $buttons  [['id' => 'book', 'title' => 'Book Now'], ...]
     */
    public function sendButtons(string $to, string $body, array $buttons, ?string $header = null, ?string $footer = null, ?ChannelAccount $account = null): array
    {
        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => $body],
            'action' => [
                'buttons' => array_map(fn ($b) => [
                    'type'  => 'reply',
                    'reply' => ['id' => $b['id'], 'title' => mb_substr($b['title'], 0, 20)],
                ], array_slice($buttons, 0, 3)),
            ],
        ];

        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => $footer];
        }

        return $this->dispatch([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizeNumber($to),
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ], $account);
    }

    public function sendLocation(string $to, float $latitude, float $longitude, ?string $name = null, ?string $address = null, ?ChannelAccount $account = null): array
    {
        return $this->dispatch([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizeNumber($to),
            'type'              => 'location',
            'location'          => array_filter([
                'latitude'  => $latitude,
                'longitude' => $longitude,
                'name'      => $name,
                'address'   => $address,
            ], fn ($v) => $v !== null),
        ], $account);
    }

    /**
     * Blue ticks on the customer's side; also stops Meta re-delivering.
     */
    public function markAsRead(string $providerMessageId, ?ChannelAccount $account = null): array
    {
        return $this->dispatch([
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $providerMessageId,
        ], $account);
    }

    private function dispatch(array $payload, ?ChannelAccount $account): array
    {
        return $this->client($account)->post(
            $this->phoneNumberId($account) . '/messages',
            $payload,
            $this->token($account),
        );
    }

    private function headerParameter(array $header): array
    {
        $type = $header['type'] ?? 'text';

        return match ($type) {
            'image', 'video', 'document' => [
                'type' => $type,
                $type  => array_filter([
                    'link'     => $header['link'] ?? null,
                    'id'       => $header['id'] ?? null,
                    'filename' => $header['filename'] ?? null,
                ], fn ($v) => $v !== null),
            ],
            default => ['type' => 'text', 'text' => (string) ($header['text'] ?? '')],
        };
    }

    // -----------------------------------------------------------------
    // Media
    // -----------------------------------------------------------------

    /**
     * Upload a local file to Meta and return the resulting media id.
     * Media ids are valid for 30 days and can be reused across sends.
     */
    public function uploadMedia(string $absolutePath, string $mimeType, ?ChannelAccount $account = null): string
    {
        $response = $this->client($account)->upload(
            $this->phoneNumberId($account) . '/media',
            $absolutePath,
            'file',
            ['messaging_product' => 'whatsapp', 'type' => $mimeType],
            $this->token($account),
        );

        if (empty($response['id'])) {
            throw new MetaException('WhatsApp media upload did not return a media id.');
        }

        return $response['id'];
    }

    /**
     * Resolve a media id to its (short-lived, token-protected) CDN URL.
     */
    public function mediaInfo(string $mediaId, ?ChannelAccount $account = null): array
    {
        return $this->client($account)->get($mediaId, [], $this->token($account));
    }

    /**
     * Fetch inbound media and persist it on the configured disk.
     *
     * @return array{path:string, mime:?string, size:int, sha256:?string}|null
     */
    public function downloadMedia(string $mediaId, ?ChannelAccount $account = null): ?array
    {
        $client = $this->client($account);

        if ($client->isThirdParty()) {
            [$binary, $mime] = $client->providerMedia($mediaId);
            $info = [];
        } else {
            $info = $this->mediaInfo($mediaId, $account);

            $url = $info['url'] ?? null;
            if (! $url) {
                return null;
            }

            $binary = $client->download($url, $this->token($account));
            $mime   = $info['mime_type'] ?? null;
        }

        if ($binary === null) {
            return null;
        }

        $path = sprintf(
            '%s/%s/%s.%s',
            trim(config('meta.whatsapp.media_path'), '/'),
            now()->format('Y/m'),
            $mediaId,
            $this->extensionFor($mime),
        );

        Storage::disk(config('meta.whatsapp.media_disk'))->put($path, $binary);

        return [
            'path'   => $path,
            'mime'   => $mime,
            'size'   => strlen($binary),
            'sha256' => $info['sha256'] ?? null,
        ];
    }

    public function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'video/mp4'  => 'mp4',
            'video/3gpp' => '3gp',
            'audio/mpeg' => 'mp3',
            'audio/mp4'  => 'm4a',
            'audio/ogg'  => 'ogg',
            'audio/amr'  => 'amr',
            'audio/aac'  => 'aac',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            default => 'bin',
        };
    }

    /**
     * Map a mime type onto the WhatsApp message type it must be sent as.
     */
    public function messageTypeFor(string $mime): string
    {
        foreach (config('meta.whatsapp.allowed_mimes') as $type => $mimes) {
            if (in_array($mime, $mimes, true)) {
                return $type;
            }
        }

        return 'document';
    }

    // -----------------------------------------------------------------
    // Templates
    // -----------------------------------------------------------------

    public function listTemplates(?ChannelAccount $account = null): array
    {
        return $this->client($account)->paginate(
            $this->wabaId($account) . '/message_templates',
            ['fields' => 'id,name,status,category,language,components,rejected_reason,quality_score'],
            maxPages: 20,
            token: $this->token($account),
        );
    }

    /**
     * Submit a new template for Meta review.
     */
    public function createTemplate(array $payload, ?ChannelAccount $account = null): array
    {
        return $this->client($account)->post(
            $this->wabaId($account) . '/message_templates',
            $payload,
            $this->token($account),
        );
    }

    public function deleteTemplate(string $name, ?ChannelAccount $account = null): array
    {
        return $this->client($account)->delete(
            $this->wabaId($account) . '/message_templates',
            ['name' => $name],
            $this->token($account),
        );
    }

    // -----------------------------------------------------------------
    // Account introspection (used by the "Test connection" button)
    // -----------------------------------------------------------------

    public function phoneNumbers(?ChannelAccount $account = null): array
    {
        return $this->client($account)->get(
            $this->wabaId($account) . '/phone_numbers',
            ['fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type,throughput'],
            $this->token($account),
        )['data'] ?? [];
    }

    public function businessProfile(?ChannelAccount $account = null): array
    {
        return $this->client($account)->get(
            $this->phoneNumberId($account) . '/whatsapp_business_profile',
            ['fields' => 'about,address,description,email,profile_picture_url,websites,vertical'],
            $this->token($account),
        )['data'][0] ?? [];
    }

    public function updateBusinessProfile(array $fields, ?ChannelAccount $account = null): array
    {
        return $this->client($account)->post(
            $this->phoneNumberId($account) . '/whatsapp_business_profile',
            array_merge(['messaging_product' => 'whatsapp'], $fields),
            $this->token($account),
        );
    }

    /**
     * Register the app's webhook subscription on the WABA. Meta will only
     * deliver events once the WABA is subscribed to the app.
     */
    public function subscribeWebhook(?ChannelAccount $account = null): array
    {
        return $this->client($account)->post(
            $this->wabaId($account) . '/subscribed_apps',
            [],
            $this->token($account),
        );
    }

    public function subscribedApps(?ChannelAccount $account = null): array
    {
        return $this->client($account)->get(
            $this->wabaId($account) . '/subscribed_apps',
            [],
            $this->token($account),
        )['data'] ?? [];
    }

    /**
     * The app's own webhook configuration for WhatsApp Business Accounts
     * (callback URL, fields, active), or null when none is set up. This is
     * read with the app token, so it works even when Meta's per-WABA
     * subscribed_apps read-back is failing.
     */
    public function appWebhookSubscription(): ?array
    {
        // App-level check for the app under Settings → Integrations, so it
        // must not be redirected to the default sender's app.
        foreach ($this->meta->appSubscriptions() as $subscription) {
            if (($subscription['object'] ?? null) === 'whatsapp_business_account') {
                return $subscription;
            }
        }

        return null;
    }
}
