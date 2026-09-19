<?php

namespace App\Jobs;

use App\Models\FollowUp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFollowUpRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $followUps = FollowUp::with(['lead', 'user'])
            ->pending()
            ->where('reminder_sent', false)
            ->where('scheduled_at', '<=', now()->addMinutes(30))
            ->get();

        foreach ($followUps as $followUp) {
            try {
                // WhatsApp / SMS notification to telecaller would go here
                Log::info("Follow-up reminder: Lead #{$followUp->lead_id} scheduled at {$followUp->scheduled_at}");

                $followUp->update(['reminder_sent' => true]);
            } catch (\Throwable $e) {
                Log::error("Follow-up reminder failed for ID {$followUp->id}: " . $e->getMessage());
            }
        }
    }
}
