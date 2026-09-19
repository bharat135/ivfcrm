<?php

namespace App\Jobs;

use App\Models\MetaLeadForm;
use App\Services\Meta\MetaException;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetch one lead's answers from the Graph API after the leadgen webhook fires,
 * then create the CRM lead (dedup + auto-assignment handled by LeadService).
 */
class ImportMetaLeadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(
        private string $leadgenId,
        private string $formId = '',
        private array $context = [],
    ) {}

    public function handle(MetaAdsService $ads, MetaSyncService $sync): void
    {
        $form = $this->formId
            ? MetaLeadForm::where('external_id', $this->formId)->first()
            : null;

        try {
            $remote = $ads->getLead($this->leadgenId, $form?->page_id ?? ($this->context['page_id'] ?? null));
        } catch (MetaException $e) {
            // A lead that has been deleted on Meta's side will never resolve.
            if (! $e->isTransient()) {
                Log::warning('Meta lead unavailable; giving up', [
                    'leadgen_id' => $this->leadgenId,
                    'error'      => $e->displayMessage(),
                ]);

                return;
            }

            throw $e;
        }

        $sync->importLead($remote, $form, $this->context);
    }
}
