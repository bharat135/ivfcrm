<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Smartflo user/agent mirrored from Tata Tele Business Services and, once
 * linked, the CRM user it belongs to.
 */
class TataAgent extends Model
{
    protected $fillable = [
        'user_id', 'smartflo_user_id', 'agent_id', 'name', 'login_id', 'email',
        'follow_me_number', 'extension', 'is_login_based_calling', 'is_web_login',
        'is_active', 'sync_status', 'sync_direction', 'last_synced_at', 'last_error', 'raw',
    ];

    protected $casts = [
        'is_login_based_calling' => 'boolean',
        'is_web_login'           => 'boolean',
        'is_active'              => 'boolean',
        'last_synced_at'         => 'datetime',
        'raw'                    => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** What Smartflo should be told to ring: the mobile, else the extension, else the agent id. */
    public function dialIdentity(): ?string
    {
        return $this->follow_me_number ?: $this->extension ?: $this->agent_id;
    }

    public function statusBadge(): string
    {
        return match ($this->sync_status) {
            'linked'  => 'success',
            'pending' => 'warning',
            'error'   => 'danger',
            default   => 'secondary',
        };
    }
}
