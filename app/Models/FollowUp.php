<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    protected $fillable = [
        'lead_id', 'user_id', 'disposition_id', 'type', 'scheduled_at',
        'status', 'is_important', 'completed_at', 'remark', 'reminder_sent',
        'delegated_to', 'delegated_reason',
    ];

    /** The agent covering this follow-up while the owner is on leave. */
    public function delegate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    /** Follow-ups an agent must work: their own (unless covered) plus the ones they cover. */
    public function scopeForAgent($query, int $userId)
    {
        return $query->where(fn ($q) => $q
            ->where(fn ($own) => $own->where('user_id', $userId)->whereNull('delegated_to'))
            ->orWhere('delegated_to', $userId));
    }

    /**
     * The follow-ups a user's list shows: admins and management see the whole
     * team's, a centre manager their centre's leads plus their own, and an
     * agent the ones they must work (forAgent).
     */
    public function scopeVisibleTo($query, User $user)
    {
        if (static::supervises($user)) {
            return $query;
        }

        if ($user->hasRole('Center Manager') && $user->center_id) {
            return $query->where(fn ($q) => $q
                ->whereHas('lead', fn ($lead) => $lead->where('center_id', $user->center_id))
                ->orWhere(fn ($own) => $own->forAgent($user->id)));
        }

        return $query->forAgent($user->id);
    }

    /** Whether the user's list covers other agents' follow-ups as well as their own. */
    public static function supervises(User $user): bool
    {
        return $user->hasRole(['Super Admin', 'Admin', 'Management']);
    }

    protected $casts = [
        'scheduled_at'  => 'datetime',
        'completed_at'  => 'datetime',
        'reminder_sent' => 'boolean',
        'is_important'  => 'boolean',
    ];

    public function lead(): BelongsTo        { return $this->belongsTo(Lead::class); }
    public function user(): BelongsTo        { return $this->belongsTo(User::class); }
    public function disposition(): BelongsTo { return $this->belongsTo(Disposition::class); }

    /** Follow-ups the agent flagged as must-not-slip when dispositioning. */
    public function scopeImportant($query)
    {
        return $query->where('is_important', true);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')->where('scheduled_at', '<', now());
    }

    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_at', today());
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isDue(): bool
    {
        return $this->status === 'pending' && $this->scheduled_at->isPast();
    }
}
