<?php

namespace App\Jobs;

use App\Models\ChannelAccount;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Periodic reconciliation with Meta: ad structure, daily insights, lead forms
 * and WhatsApp templates.
 *
 * `$parts` lets the "Sync now" buttons in the UI refresh one area cheaply
 * instead of pulling the whole account.
 */
class SyncMetaAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  array  $parts  Any of: structure, insights, forms, templates
     */
    public function __construct(
        private array $parts = ['structure', 'insights', 'forms', 'templates'],
        private ?string $since = null,
        private ?string $until = null,
    ) {}

    public function handle(MetaSyncService $sync, MetaClient $client): void
    {
        if (! $client->isConfigured()) {
            Log::info('Meta sync skipped: no access token configured.');

            return;
        }

        $result = [];

        if (in_array('structure', $this->parts, true) && $client->isAdsConfigured()) {
            $result['structure'] = $this->guard('structure', fn () => $sync->syncStructure());
        }

        if (in_array('insights', $this->parts, true) && $client->isAdsConfigured()) {
            $result['insights'] = $this->guard('insights', fn () => $sync->syncInsights(
                $this->since ? Carbon::parse($this->since) : null,
                $this->until ? Carbon::parse($this->until) : null,
            ));
        }

        if (in_array('forms', $this->parts, true) && $client->pageId()) {
            $result['forms'] = $this->guard('forms', fn () => $sync->syncLeadForms());
        }

        if (in_array('templates', $this->parts, true) && $client->wabaId()) {
            $result['templates'] = $this->guard('templates', fn () => $sync->syncTemplates());
        }

        // Numbers with their own WABA (another business or Meta app) keep
        // their own template library; pull each so the picker offers them.
        if (in_array('templates', $this->parts, true)) {
            $accounts = ChannelAccount::where('channel', 'whatsapp')->where('is_active', true)->get()
                ->filter(fn (ChannelAccount $a) => ! empty($a->credentials['waba_id']));

            foreach ($accounts as $account) {
                $result['templates:' . $account->id] = $this->guard(
                    'templates for ' . $account->name,
                    fn () => $sync->syncTemplates($account),
                );
            }
        }

        // The settings screen reads this to show "last synced" without a table.
        Cache::put('meta.last_sync', [
            'at'     => now()->toDateTimeString(),
            'result' => $result,
        ], now()->addDays(30));

        Log::info('Meta account sync complete', $result);
    }

    /**
     * One failing edge (e.g. no WABA yet) must not abort the whole sync.
     */
    private function guard(string $label, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            Log::warning("Meta sync part [{$label}] failed", ['error' => $e->getMessage()]);

            return 'error: ' . $e->getMessage();
        }
    }
}
