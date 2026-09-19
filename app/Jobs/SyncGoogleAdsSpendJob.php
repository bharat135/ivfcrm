<?php

namespace App\Jobs;

use App\Services\Google\GoogleAdsException;
use App\Services\Google\GoogleAdsInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pulls Google Ads spend so cost-per-patient is comparable with Meta.
 *
 * Runs on a rolling window rather than fetching only yesterday: Google restates
 * recent days as invalid clicks are removed, so a single-day pull would leave
 * the CRM permanently a few percent out.
 */
class SyncGoogleAdsSpendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public function handle(GoogleAdsInsightService $insights): void
    {
        if (! $insights->isConfigured()) {
            return;
        }

        try {
            $result = $insights->sync(
                $this->from ? Carbon::parse($this->from) : null,
                $this->to ? Carbon::parse($this->to) : null,
            );

            Cache::put('google_ads.last_sync', now(), now()->addDays(7));
            Cache::forget('google_ads.last_sync_error');

            Log::info('Google Ads spend sync finished', $result);
        } catch (GoogleAdsException $e) {
            // Surfaced on the settings screen rather than only in the log, so a
            // revoked refresh token is visible to whoever can fix it.
            Cache::put('google_ads.last_sync_error', $e->getMessage(), now()->addDays(7));

            Log::warning('Google Ads spend sync failed', ['error' => $e->getMessage()]);
        }
    }
}
