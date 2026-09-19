<?php

namespace App\Jobs;

use App\Models\MetaLeadForm;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Backstop for the leadgen webhook: every 15 minutes, queue one pull per
 * auto-sync Lead Ads form for submissions the webhook did not deliver (page
 * not subscribed, app in development mode, a missed retry, downtime).
 *
 * The work itself happens in SyncMetaLeadFormJob, one form at a time, so a
 * large backfill on one form never blocks the rest. Forms that have never
 * been pulled go first. Dedup on `external_lead_id` makes overlap harmless.
 */
class SyncMetaLeadFormsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(MetaSyncService $sync, MetaClient $client): void
    {
        if (! $client->isConfigured()) {
            return;
        }

        MetaLeadForm::active()
            ->where('auto_sync', true)
            ->orderByRaw('leads_synced_at IS NULL DESC')
            ->orderBy('leads_synced_at')
            ->get()
            ->each(fn (MetaLeadForm $form) => SyncMetaLeadFormJob::dispatch($form));
    }
}
