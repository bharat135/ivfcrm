<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw signalling events behind a call — Asterisk dialplan callbacks and Meta
 * WhatsApp calling webhooks. Kept so a disputed or dropped call can be
 * reconstructed without reading PBX logs.
 */
class CallEvent extends Model
{
    protected $fillable = [
        'call_log_id', 'source', 'event', 'correlation_id', 'payload', 'occurred_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
    ];

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    public function scopeForCall($query, string $correlationId)
    {
        return $query->where('correlation_id', $correlationId);
    }

    public function icon(): string
    {
        return match ($this->event) {
            'originate', 'dial_begin' => 'bi-telephone-outbound',
            'ringing'                 => 'bi-bell',
            'answered', 'bridge'      => 'bi-telephone-fill',
            'hold'                    => 'bi-pause-circle',
            'unhold'                  => 'bi-play-circle',
            'transfer'                => 'bi-arrow-left-right',
            'hangup', 'terminate'     => 'bi-telephone-x',
            'recording'               => 'bi-record-circle',
            default                   => 'bi-dot',
        };
    }
}
