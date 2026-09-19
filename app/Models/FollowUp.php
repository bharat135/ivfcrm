<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    protected $fillable = [
        'lead_id', 'user_id', 'disposition_id', 'type', 'scheduled_at',
        'status', 'completed_at', 'remark', 'reminder_sent',
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

    protected $casts = [
        'scheduled_at'  => 'datetime',
        'completed_at'  => 'datetime',
        'reminder_sent' => 'boolean',
    ];

    public function lead(): BelongsTo        { return $this->belongsTo(Lead::class); }
    public function user(): BelongsTo        { return $this->belongsTo(User::class); }
    public function disposition(): BelongsTo { return $this->belongsTo(Disposition::class); }

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
