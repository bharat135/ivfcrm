<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\AssignmentEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * An assigned lead that its owner has not called within the idle window
 * (default 30 minutes) is handed to another agent who is online. Runs every
 * five minutes; only recent enquiries are moved, so a historical backfill
 * does not churn between agents forever.
 */
class ReassignIdleLeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(AssignmentEngine $engine): void
    {
        if (! $engine->onlineAgentsExist()) {
            return;
        }

        $idle   = $engine->idleMinutes();
        $maxAge = $engine->idleMaxAgeHours();

        Lead::query()
            ->whereNotNull('assigned_to')
            ->whereNotNull('assigned_at')
            ->whereNull('merged_into_id')
            ->where('status', 'new')
            ->where('assigned_at', '<=', now()->subMinutes($idle))
            ->where('lead_date', '>=', now()->subHours($maxAge))
            ->whereDoesntHave('callLogs', fn ($q) => $q->whereColumn('call_logs.created_at', '>=', 'leads.assigned_at'))
            ->orderBy('assigned_at')
            ->limit(200)
            ->get()
            ->each(fn (Lead $lead) => $engine->reassignIdle($lead));
    }
}
