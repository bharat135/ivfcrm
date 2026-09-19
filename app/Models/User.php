<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'employee_code',
        'email',
        'phone',
        'password',
        'center_id',
        'reports_to',
        'designation',
        'language',
        'is_active',
        'daily_lead_cap',
        'is_available',
        'call_status',
        'call_status_changed_at',
        'active_call_log_id',
        'tata_agent_number',
        'meta',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'current_session_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'daily_lead_cap' => 'integer',
            'call_status_changed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    // Relationships
    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reports_to');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    // ---------------------------------------------------------------
    // Telephony
    // ---------------------------------------------------------------

    public function sipExtension(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SipExtension::class)->where('is_active', true);
    }

    public function activeCall(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'active_call_log_id');
    }

    /** The Smartflo agent this user is linked to, if any. */
    public function tataAgent(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(TataAgent::class);
    }

    /** Whether this agent can place calls through the active provider right now. */
    public function canPlaceCalls(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (app(\App\Services\Telephony\CallService::class)->usesTata()) {
            return (bool) ($this->tata_agent_number ?: $this->phone);
        }

        return $this->sipExtension()->exists();
    }

    /** What the active provider will ring for this agent: extension or Tata number. */
    public function dialIdentity(): ?string
    {
        if (app(\App\Services\Telephony\CallService::class)->usesTata()) {
            return $this->tata_agent_number ?: $this->phone;
        }

        return $this->sipExtension?->extension;
    }

    public function isOnCall(): bool
    {
        return in_array($this->call_status, ['ringing', 'on_call'], true);
    }

    public function callStatusBadge(): string
    {
        return match ($this->call_status) {
            'idle'    => 'success',
            'ringing' => 'warning',
            'on_call' => 'primary',
            'wrapup'  => 'info',
            'break', 'dnd' => 'secondary',
            default   => 'secondary',
        };
    }

    public function callStatusLabel(): string
    {
        return match ($this->call_status) {
            'idle'    => 'Available',
            'ringing' => 'Ringing',
            'on_call' => 'On a call',
            'wrapup'  => 'Wrapping up',
            'break'   => 'On break',
            'dnd'     => 'Do not disturb',
            default   => 'Offline',
        };
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)->where('is_available', true);
    }
}
