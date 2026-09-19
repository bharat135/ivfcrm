<?php

namespace App\Services;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;

/**
 * What an agent should open next, so the profile flows straight on after a
 * disposition instead of going back to a list.
 *
 * Priority, as the clinic asked for it:
 *   1. leads assigned to the agent that nobody has called yet (oldest first —
 *      a fresh enquiry goes cold within minutes);
 *   2. the agent's pending follow-ups whose time has arrived, earliest first.
 */
class NextLeadFinder
{
    /** Minutes before a follow-up's exact time that it counts as due. */
    public const DUE_GRACE_MINUTES = 5;

    /**
     * @return array{lead: Lead, reason: string}|null
     */
    public function for(User $user, ?int $afterLeadId = null): ?array
    {
        $uncalled = Lead::query()
            ->where('assigned_to', $user->id)
            ->where('status', 'new')
            ->where('contact_attempts', 0)
            ->whereNull('first_contacted_at')
            ->when($afterLeadId, fn ($q) => $q->where('id', '!=', $afterLeadId))
            ->orderByRaw('assigned_at IS NULL')
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->first();

        if ($uncalled) {
            $age = $uncalled->assigned_at?->diffForHumans(null, true) ?? 'a while';

            return ['lead' => $uncalled, 'reason' => "New enquiry assigned to you {$age} ago, not called yet."];
        }

        $due = FollowUp::query()
            ->with('lead')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now()->addMinutes(self::DUE_GRACE_MINUTES))
            ->when($afterLeadId, fn ($q) => $q->where('lead_id', '!=', $afterLeadId))
            ->whereHas('lead', fn ($q) => $q->whereNull('merged_into_id'))
            ->orderBy('scheduled_at')
            ->first();

        if ($due && $due->lead) {
            return [
                'lead'   => $due->lead,
                'reason' => 'Follow-up due at ' . $due->scheduled_at->format('h:i A') . ($due->scheduled_at->isPast() ? ' (overdue)' : '') . '.',
            ];
        }

        return null;
    }
}
