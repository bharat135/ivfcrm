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
use Illuminate\Support\Facades\Log;

/**
 * Backstop for the leadgen webhook: poll every auto-sync Lead Ads form for
 * submissions the webhook did not deliver (page not subscribed, app in
 * development mode, a missed retry, downtime).
 *
 * Dedup on `external_lead_id` makes re-reading a window harmless, so the
 * window deliberately overlaps the previous run.
 */
class SyncMetaLeadFormsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /** Minutes of overlap with the previous window. */
    public const OVERLAP_MINUTES = 30;

    public function handle(MetaSyncService $sync, MetaClient $client): void
    {
        if (! $client->isConfigured()) {
            return;
        }

        $forms = MetaLeadForm::active()->where('auto_sync', true)->get();
        $created = 0;

        foreach ($forms as $form) {
            // last_synced_at is also touched by the hourly metadata sync, so a
            // window starting exactly there could skip a lead that arrived
            // seconds before that sync ran.
            $since = ($form->last_synced_at ?: now()->subDays(7))
                ->copy()
                ->subMinutes(self::OVERLAP_MINUTES);

            try {
                $created += $sync->syncFormLeads($form, $since);
            } catch (\Throwable $e) {
                Log::warning('Meta lead form poll failed', [
                    'form_id' => $form->external_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($created > 0) {
            Log::info('Meta lead form poll imported leads', ['created' => $created, 'forms' => $forms->count()]);
        }
    }
}
