<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A voice broadcast built from a CRM lead segment and run on Smartflo. */
class TataBroadcast extends Model
{
    protected $fillable = [
        'smartflo_id', 'list_id', 'name', 'description', 'status', 'destination', 'caller_id',
        'filters', 'options', 'lead_count', 'batch_ids', 'stats',
        'scheduled_start_at', 'scheduled_end_at', 'created_by', 'last_synced_at', 'last_error',
    ];

    protected $casts = [
        'filters'            => 'array',
        'options'            => 'array',
        'batch_ids'          => 'array',
        'stats'              => 'array',
        'scheduled_start_at' => 'datetime',
        'scheduled_end_at'   => 'datetime',
        'last_synced_at'     => 'datetime',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(TataBroadcastLead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'running' => 'success',
            'paused'  => 'warning',
            'ended'   => 'secondary',
            'failed'  => 'danger',
            'created' => 'primary',
            default   => 'light',
        };
    }

    public function isControllable(): bool
    {
        return (bool) $this->smartflo_id && in_array($this->status, ['created', 'running', 'paused'], true);
    }
}
