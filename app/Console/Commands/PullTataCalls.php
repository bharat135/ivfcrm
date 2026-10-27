<?php

namespace App\Console\Commands;

use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\Tata\TataSyncService;
use App\Services\Telephony\TataException;
use Illuminate\Console\Command;

/**
 * Pulls recent Smartflo call records (CDR) into the call log.
 *
 * The webhook is the fast path, but when it is missing or misses an event,
 * inbound calls — missed ones above all — never reach the CRM. Scheduled
 * every five minutes over the last couple of hours, this backfills them and
 * closes CRM-placed calls whose hangup never arrived. Re-pulling a record the
 * CRM already has only fills in what is missing.
 */
class PullTataCalls extends Command
{
    protected $signature = 'tata:pull-calls {--hours=2 : How far back to fetch}';

    protected $description = 'Backfill recent Smartflo call records (missed inbound calls, durations, recordings)';

    public function handle(SmartfloClient $tata, TataSyncService $sync): int
    {
        if (! $tata->isConfigured()) {
            $this->info('Smartflo is not configured; nothing to pull.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->pullCallRecords(now()->subHours(max(1, (int) $this->option('hours'))), now());
        } catch (TataException $e) {
            $this->error('Smartflo call pull failed: ' . $e->displayMessage());

            return self::FAILURE;
        }

        $this->info("Fetched {$result['fetched']}: {$result['created']} new, {$result['updated']} updated.");

        return self::SUCCESS;
    }
}
