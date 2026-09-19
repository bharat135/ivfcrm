<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta requires an explicit, time-boxed permission before a business may place
 * a WhatsApp voice call to a user. The permission is requested in-chat, granted
 * by the user, and expires — this model mirrors that state so the dialer can
 * tell an agent *why* a call button is disabled.
 */
class WhatsappCallPermission extends Model
{
    protected $fillable = [
        'conversation_id', 'lead_id', 'patient_id', 'requested_by', 'wa_id',
        'status', 'requested_at', 'responded_at', 'expires_at', 'calls_remaining', 'meta',
    ];

    protected $casts = [
        'requested_at'    => 'datetime',
        'responded_at'    => 'datetime',
        'expires_at'      => 'datetime',
        'calls_remaining' => 'integer',
        'meta'            => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Usable right now: approved, unexpired, and with calls left on it.
     */
    public function isUsable(): bool
    {
        return $this->status === 'approved'
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->calls_remaining === null || $this->calls_remaining > 0);
    }

    public function scopeUsable($query)
    {
        return $query->where('status', 'approved')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('calls_remaining')->orWhere('calls_remaining', '>', 0));
    }

    /**
     * Why the agent cannot call — shown directly in the dialer.
     */
    public function blockReason(): ?string
    {
        if ($this->isUsable()) {
            return null;
        }

        return match (true) {
            $this->status === 'pending'  => 'Waiting for the contact to accept your call request.',
            $this->status === 'rejected' => 'The contact declined call permission.',
            $this->status === 'revoked'  => 'The contact revoked call permission.',
            $this->expires_at?->isPast() => 'Call permission has expired — request it again.',
            $this->calls_remaining === 0 => 'No calls left on this permission — request it again.',
            default                      => 'No call permission from this contact yet.',
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'approved' => $this->isUsable() ? 'success' : 'secondary',
            'pending'  => 'warning',
            'rejected', 'revoked' => 'danger',
            'expired'  => 'secondary',
            default    => 'secondary',
        };
    }
}
