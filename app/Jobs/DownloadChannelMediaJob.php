<?php

namespace App\Jobs;

use App\Models\MessageAttachment;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MessengerService;
use App\Services\Meta\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fetch inbound media out of the webhook's critical path.
 *
 * The two channel families hand over media differently:
 *
 *   WhatsApp   a media id, resolved to a short-lived CDN URL by a second call
 *   Messenger  a CDN URL directly on the webhook payload
 *   Instagram  ditto
 *
 * Both URLs expire within minutes, so this is queued to run immediately with a
 * short backoff rather than a long one — a slow retry is the same as no retry.
 */
class DownloadChannelMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 20, 60];

    public function __construct(private int $attachmentId) {}

    public function handle(WhatsAppService $whatsapp, MetaClient $client, MessengerService $messenger): void
    {
        $attachment = MessageAttachment::with('message.conversation.channelAccount')->find($this->attachmentId);

        if (! $attachment || $attachment->download_status === 'downloaded') {
            return;
        }

        $conversation = $attachment->message?->conversation;
        $channel = $conversation?->channel ?? 'whatsapp';

        $result = $attachment->source_url
            ? $this->fromUrl($attachment, $client, $messenger, $conversation, $channel)
            : $this->fromMediaId($attachment, $whatsapp, $conversation);

        if (! $result) {
            $attachment->update(['download_status' => 'failed']);

            return;
        }

        $attachment->update([
            'path'            => $result['path'],
            'disk'            => config('meta.whatsapp.media_disk'),
            'mime'            => $result['mime'] ?: $attachment->mime,
            'size'            => $result['size'],
            'sha256'          => $result['sha256'] ?? $attachment->sha256,
            'download_status' => 'downloaded',
            'filename'        => $attachment->filename ?: basename($result['path']),
        ]);
    }

    /**
     * Messenger and Instagram: the payload already carries a URL.
     */
    private function fromUrl(
        MessageAttachment $attachment,
        MetaClient $client,
        MessengerService $messenger,
        $conversation,
        string $channel,
    ): ?array {
        // Meta's CDN links for these channels are pre-signed and public, but a
        // Page token is harmless and covers the cases where they are not.
        $token = $messenger->tokenFor($conversation?->page_id, $conversation?->channelAccount);

        $binary = $client->download($attachment->source_url, $token);

        if ($binary === null) {
            Log::warning('Channel media download failed', [
                'attachment_id' => $attachment->id,
                'channel'       => $channel,
            ]);

            return null;
        }

        $mime = $attachment->mime ?: $this->guessMime($attachment->source_url, $attachment->type);

        $path = sprintf(
            '%s/%s/%s/%s.%s',
            trim(config('meta.whatsapp.media_path'), '/'),
            $channel,
            now()->format('Y/m'),
            $attachment->id . '-' . substr(sha1($attachment->source_url), 0, 8),
            $this->extensionFor($mime, $attachment->type),
        );

        Storage::disk(config('meta.whatsapp.media_disk'))->put($path, $binary);

        return ['path' => $path, 'mime' => $mime, 'size' => strlen($binary)];
    }

    /**
     * WhatsApp: resolve the media id, then fetch.
     */
    private function fromMediaId(MessageAttachment $attachment, WhatsAppService $whatsapp, $conversation): ?array
    {
        if (! $attachment->provider_media_id) {
            $attachment->update(['download_status' => 'na']);

            return null;
        }

        return $whatsapp->downloadMedia($attachment->provider_media_id, $conversation?->channelAccount);
    }

    /**
     * Meta does not send a content type for Messenger attachments, so it is
     * inferred from the URL and the declared attachment type.
     */
    private function guessMime(string $url, ?string $type): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'mp4'  => 'video/mp4',
            'mp3'  => 'audio/mpeg',
            'm4a'  => 'audio/mp4',
            'ogg'  => 'audio/ogg',
            'pdf'  => 'application/pdf',
            default => match ($type) {
                'image' => 'image/jpeg',
                'video' => 'video/mp4',
                'audio' => 'audio/mpeg',
                default => 'application/octet-stream',
            },
        };
    }

    private function extensionFor(string $mime, ?string $type): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'video/mp4'  => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/mp4'  => 'm4a',
            'audio/ogg'  => 'ogg',
            'application/pdf' => 'pdf',
            default => match ($type) {
                'image' => 'jpg',
                'video' => 'mp4',
                'audio' => 'mp3',
                default => 'bin',
            },
        };
    }

    public function failed(\Throwable $e): void
    {
        MessageAttachment::whereKey($this->attachmentId)->update(['download_status' => 'failed']);

        Log::error('Channel media download job failed', [
            'attachment_id' => $this->attachmentId,
            'error'         => $e->getMessage(),
        ]);
    }
}
