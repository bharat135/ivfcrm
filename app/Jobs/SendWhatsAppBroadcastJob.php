<?php

namespace App\Jobs;

use App\Models\WhatsappBroadcast;
use App\Models\WhatsappBroadcastRecipient;
use App\Services\Chat\ConversationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Work through a broadcast's recipient list, sending the approved template to
 * each one and recording the message against a real conversation so replies
 * land in the inbox.
 *
 * Throttled to stay inside the WABA's messaging tier: exceeding it gets the
 * number rate-limited for everyone, including 1:1 agent replies.
 */
class SendWhatsAppBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(private int $broadcastId) {}

    public function handle(ConversationService $conversations): void
    {
        $broadcast = WhatsappBroadcast::with('template', 'channelAccount')->find($this->broadcastId);

        if (! $broadcast || ! in_array($broadcast->status, ['scheduled', 'running'], true)) {
            return;
        }

        if (! $broadcast->template || ! $broadcast->template->isApproved()) {
            $broadcast->update(['status' => 'failed', 'completed_at' => now()]);
            Log::warning('Broadcast aborted: template not approved', ['broadcast' => $broadcast->id]);

            return;
        }

        $broadcast->update(['status' => 'running', 'started_at' => $broadcast->started_at ?: now()]);

        $perMinute = max((int) config('meta.whatsapp.broadcast_rate_per_minute'), 1);
        $delay     = (int) floor(1_000_000 / max($perMinute / 60, 0.1));

        $broadcast->recipients()
            ->where('status', 'pending')
            ->chunkById(200, function ($recipients) use ($broadcast, $conversations, $delay) {
                foreach ($recipients as $recipient) {
                    // A cancel from the UI takes effect between recipients.
                    if ($broadcast->fresh()->status === 'cancelled') {
                        return false;
                    }

                    $this->sendOne($broadcast, $recipient, $conversations);
                    usleep($delay);
                }

                return true;
            });

        $broadcast->refresh();

        $broadcast->update([
            'status'       => $broadcast->status === 'cancelled' ? 'cancelled' : 'completed',
            'completed_at' => now(),
        ]);
    }

    private function sendOne(WhatsappBroadcast $broadcast, WhatsappBroadcastRecipient $recipient, ConversationService $conversations): void
    {
        try {
            $conversation = $conversations->resolve(
                phone: $recipient->mobile,
                channel: 'whatsapp',
                account: $broadcast->channelAccount,
                contactName: $recipient->name,
                lead: $recipient->lead,
                patient: $recipient->patient,
            );

            $message = $conversations->sendTemplate(
                $conversation,
                $broadcast->template,
                $recipient->variables ?? [],
                [],
                $broadcast->created_by,
            );

            $recipient->update([
                'status'     => 'sent',
                'message_id' => $message->id,
                'sent_at'    => now(),
            ]);

            $broadcast->increment('sent_count');
        } catch (\Throwable $e) {
            $recipient->update([
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 500),
            ]);

            $broadcast->increment('failed_count');
        }
    }

    public function failed(\Throwable $e): void
    {
        WhatsappBroadcast::whereKey($this->broadcastId)->update([
            'status'       => 'failed',
            'completed_at' => now(),
        ]);

        Log::error('WhatsApp broadcast job failed', [
            'broadcast' => $this->broadcastId,
            'error'     => $e->getMessage(),
        ]);
    }
}
