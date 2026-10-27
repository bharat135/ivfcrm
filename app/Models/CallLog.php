<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One row per conversation, whatever carried it: a PSTN call through Asterisk,
 * an internal extension-to-extension call, or a WhatsApp voice call.
 */
class CallLog extends Model
{
    protected $fillable = [
        'lead_id', 'patient_id', 'user_id', 'center_id', 'provider', 'channel', 'direction',
        'from_number', 'to_number', 'provider_call_id', 'channel_id', 'linked_id',
        'extension', 'queue', 'status', 'hangup_cause', 'hangup_by',
        'duration_seconds', 'ring_seconds', 'talk_seconds', 'hold_seconds', 'wrap_seconds',
        'recording_url', 'recording_path', 'recording_disk', 'is_recorded',
        'parent_call_log_id', 'transferred_to', 'wa_call_id', 'wa_direction',
        'disposition_id', 'sub_disposition_id', 'remark', 'meta',
        'started_at', 'answered_at', 'ended_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'answered_at' => 'datetime',
        'ended_at'    => 'datetime',
        'is_recorded' => 'boolean',
        'meta'        => 'array',
    ];

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function lead(): BelongsTo           { return $this->belongsTo(Lead::class); }
    public function patient(): BelongsTo        { return $this->belongsTo(Patient::class); }
    public function user(): BelongsTo           { return $this->belongsTo(User::class); }
    public function center(): BelongsTo         { return $this->belongsTo(Center::class); }
    public function disposition(): BelongsTo    { return $this->belongsTo(Disposition::class, 'disposition_id'); }
    public function subDisposition(): BelongsTo { return $this->belongsTo(Disposition::class, 'sub_disposition_id'); }
    public function transferredTo(): BelongsTo  { return $this->belongsTo(User::class, 'transferred_to'); }
    public function parentCall(): BelongsTo     { return $this->belongsTo(CallLog::class, 'parent_call_log_id'); }

    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class)->orderBy('occurred_at');
    }

    public function transcript(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CallTranscript::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(CallLog::class, 'parent_call_log_id');
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getDurationFormattedAttribute(): string
    {
        return $this->formatSeconds($this->talk_seconds ?: $this->duration_seconds);
    }

    public function formatSeconds(?int $seconds): string
    {
        $seconds = max((int) $seconds, 0);

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /** The other party's number, whichever leg this row represents. */
    public function contactNumber(): ?string
    {
        return $this->direction === 'inbound' ? $this->from_number : $this->to_number;
    }

    public function contactName(): string
    {
        return $this->lead?->name
            ?: ($this->patient && $this->patient->name !== 'Unknown' ? $this->patient->name : null)
            ?: $this->contactNumber()
            ?: 'Unknown';
    }

    public function isConnected(): bool
    {
        return in_array($this->status, ['completed', 'answered'], true) && $this->talk_seconds > 0;
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['ringing', 'answered', 'in_progress'], true);
    }

    public function hasRecording(): bool
    {
        if ($this->recording_path) {
            return Storage::disk($this->recording_disk ?: 'local')->exists($this->recording_path);
        }

        return ! empty($this->recording_url);
    }

    /**
     * Recordings stream through an authorised route, never a public URL —
     * including provider-hosted ones (Smartflo), which are proxied so the
     * clinic's call audio is only reachable by a signed-in user.
     */
    public function recordingUrl(): ?string
    {
        if ($this->recording_path || $this->recording_url) {
            return route('calls.recording', $this);
        }

        return null;
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'completed', 'answered'  => 'success',
            'ringing', 'in_progress' => 'primary',
            'no-answer', 'no_answer' => 'warning',
            'busy'                   => 'info',
            'failed', 'congestion'   => 'danger',
            'cancelled'              => 'secondary',
            default                  => 'secondary',
        };
    }

    public function channelIcon(): string
    {
        return match ($this->channel) {
            'whatsapp' => 'bi-whatsapp',
            'internal' => 'bi-building',
            'webrtc'   => 'bi-mic',
            default    => 'bi-telephone',
        };
    }

    public function hangupCauseLabel(): ?string
    {
        if ($this->hangup_cause === null) {
            return null;
        }

        return config('telephony.hangup_causes')[(int) $this->hangup_cause] ?? $this->hangup_cause;
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeConnected($query)
    {
        return $query->whereIn('status', ['completed', 'answered'])->where('talk_seconds', '>', 0);
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', ['ringing', 'answered', 'in_progress']);
    }

    /** Inbound Smartflo calls that rang out without anyone picking up. */
    public function scopeMissed($query)
    {
        return $query->where('provider', 'tata')
            ->where('direction', 'inbound')
            ->whereNotNull('ended_at')
            ->whereNotIn('status', ['completed', 'answered', 'ringing', 'in_progress'])
            ->where(fn ($q) => $q->whereNull('talk_seconds')->orWhere('talk_seconds', 0));
    }

    /**
     * Like visibleTo, but a missed call no agent was matched to (it rang a
     * queue) is everyone's to call back.
     */
    public function scopeMissedVisibleTo($query, User $user)
    {
        if ($user->hasRole(['Super Admin', 'Admin', 'Management', 'Marketing Head', 'Center Manager', 'Call Quality Analyst'])) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('user_id', $user->id)->orWhereNull('user_id'));
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Agents see their own calls; supervisors and quality see everything.
     */
    public function scopeVisibleTo($query, User $user)
    {
        if ($user->hasRole(['Super Admin', 'Admin', 'Management', 'Marketing Head', 'Center Manager', 'Call Quality Analyst'])) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }
}
