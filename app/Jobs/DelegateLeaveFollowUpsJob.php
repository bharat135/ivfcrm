<?php

namespace App\Jobs;

use App\Models\AgentLeave;
use App\Models\FollowUp;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\AssignmentEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Leave cover for follow-up calls.
 *
 * A pending follow-up whose owner is on leave on the day it is due is
 * delegated to another agent for that day; the LEAD keeps its owner. When
 * the owner is back (or the leave is deleted) the delegation is cleared
 * again. Runs every 15 minutes and whenever a leave is saved.
 */
class DelegateLeaveFollowUpsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /** Per-run memo of user|date => away, so one run never reuses a stale answer. */
    private array $away = [];

    public function handle(AssignmentEngine $engine): void
    {
        $this->away = [];
        $horizon = now()->addDays(14);

        $pending = FollowUp::query()
            ->with(['user:id,name,center_id', 'delegate:id,name'])
            ->where('status', 'pending')
            ->whereNotNull('user_id')
            ->whereBetween('scheduled_at', [now()->startOfDay(), $horizon])
            ->orderBy('scheduled_at')
            ->get();

        foreach ($pending as $followUp) {
            $ownerAway = $this->isAway($followUp->user_id, $followUp->scheduled_at);

            if (! $ownerAway) {
                if ($followUp->delegated_to) {
                    $followUp->forceFill(['delegated_to' => null, 'delegated_reason' => null])->save();
                }

                continue;
            }

            // Already covered by someone who is present that day.
            if ($followUp->delegated_to && ! $this->isAway($followUp->delegated_to, $followUp->scheduled_at)) {
                continue;
            }

            $cover = $engine->pickCover($followUp->user, $followUp->scheduled_at);

            if (! $cover) {
                continue;
            }

            $followUp->forceFill([
                'delegated_to'     => $cover->id,
                'delegated_reason' => ($followUp->user?->name ?? 'Owner') . ' on leave',
            ])->save();

            LeadActivity::create([
                'lead_id'     => $followUp->lead_id,
                'user_id'     => null,
                'type'        => 'followup',
                'title'       => 'Follow-up covered by ' . $cover->name,
                'description' => ($followUp->user?->name ?? 'The owner') . ' is on leave on '
                    . $followUp->scheduled_at->format('d-m-Y') . '; the lead stays with them.',
                'properties'  => ['follow_up_id' => $followUp->id, 'delegated_to' => $cover->id],
            ]);
        }
    }

    private function isAway(int $userId, $date): bool
    {
        $key = $userId . '|' . \Illuminate\Support\Carbon::parse($date)->toDateString();

        return $this->away[$key] ??= AgentLeave::affecting($userId)->onDate($date)->exists();
    }
}
