<?php

namespace App\Jobs;

use App\Models\MetaLeadForm;
use App\Services\Meta\MetaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pull one Lead Ads form's new submissions. One job per form keeps each
 * unit of work short (a single form's window, not the whole account), so a
 * 1,000-lead backfill on one form cannot time out the poll for the others,
 * and the queue interleaves webhooks and other jobs between forms.
 */
class SyncMetaLeadFormJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    /** A form already waiting in the queue is not queued again. */
    public int $uniqueFor = 1800;

    public function __construct(public MetaLeadForm $form) {}

    public function uniqueId(): string
    {
        return (string) $this->form->id;
    }

    public function handle(MetaSyncService $sync): void
    {
        $form = $this->form->fresh();

        if (! $form || $form->status !== 'ACTIVE' || ! $form->auto_sync) {
            return;
        }

        try {
            $created = $sync->syncFormLeads($form);
        } catch (\Throwable $e) {
            Log::warning('Meta lead form poll failed', [
                'form_id' => $form->external_id,
                'error'   => $e->getMessage(),
            ]);

            return;
        }

        if ($created > 0) {
            Log::info('Meta lead form poll imported leads', ['form' => $form->name, 'created' => $created]);
        }
    }
}
