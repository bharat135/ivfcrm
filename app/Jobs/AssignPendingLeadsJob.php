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
 * Leads that arrived while no agent was online stay unassigned rather than
 * being pushed at somebody who is not there. This runs every minute and
 * hands them out as soon as an agent is present.
 */
class AssignPendingLeadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(AssignmentEngine $engine): void
    {
        if (! $engine->onlineAgentsExist()) {
            return;
        }

        Lead::query()
            ->whereNull('assigned_to')
            ->whereNull('merged_into_id')
            ->whereIn('status', ['new', 'working'])
            ->where('created_at', '>=', now()->subDays(30))
            // Newest first: a fresh enquiry goes cold in minutes, and the
            // job runs every minute so the older ones follow right behind.
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->each(fn (Lead $lead) => $engine->assign($lead));
    }
}
