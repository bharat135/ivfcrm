<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link between a CRM disposition and a Smartflo dialer disposition status.
 * `code` is the CRM slug on both sides, which is what makes the pairing stable
 * across renames.
 */
class TataDisposition extends Model
{
    protected $fillable = [
        'disposition_id', 'smartflo_list_id', 'smartflo_status_id', 'smartflo_parent_id',
        'name', 'code', 'sync_status', 'sync_direction', 'last_synced_at', 'last_error', 'raw',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'raw'            => 'array',
    ];

    public function disposition(): BelongsTo
    {
        return $this->belongsTo(Disposition::class);
    }

    public function statusBadge(): string
    {
        return match ($this->sync_status) {
            'synced'  => 'success',
            'pending' => 'warning',
            'error'   => 'danger',
            default   => 'secondary',
        };
    }
}
