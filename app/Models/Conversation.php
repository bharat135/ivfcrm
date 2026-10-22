<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel',
        'channel_account_id',
        'lead_id',
        'patient_id',
        'assigned_to',
        'contact_identifier',
        'contact_name',
        'contact_username',
        'avatar_url',
        'wa_id',
        'page_id',
        'subject',
        'status',
        'unread_count',
        'last_message_at',
        'last_inbound_at',
        'window_expires_at',
        'is_escalated',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'unread_count'      => 'integer',
            'last_message_at'   => 'datetime',
            'last_inbound_at'   => 'datetime',
            'window_expires_at' => 'datetime',
            'is_escalated'      => 'boolean',
            'meta'              => 'array',
        ];
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    // -----------------------------------------------------------------
    // WhatsApp 24-hour customer-service window
    // -----------------------------------------------------------------

    /**
     * Inside the window, free-form replies are allowed. Outside it, Meta only
     * accepts approved templates — the composer enforces this.
     */
    public function isWindowOpen(): bool
    {
        // Messenger and Instagram allow a plain reply for 24 hours, then a
        // further 6 days under a HUMAN_AGENT tag. From the agent's point of
        // view the thread is still answerable, so it counts as open.
        if (in_array($this->channel, ['messenger', 'instagram'], true)) {
            return $this->last_inbound_at !== null
                && $this->last_inbound_at->diffInDays(now()) < 7;
        }

        if ($this->channel !== 'whatsapp') {
            return true;
        }

        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }

    public function windowRemaining(): ?string
    {
        if (! $this->isWindowOpen()) {
            return null;
        }

        if (in_array($this->channel, ['messenger', 'instagram'], true)) {
            return $this->last_inbound_at?->copy()->addDays(7)->diffForHumans(null, true);
        }

        return $this->window_expires_at?->diffForHumans(null, true);
    }

    /**
     * True once a plain reply is no longer allowed and only a tagged
     * human-agent follow-up will be delivered.
     */
    public function needsHumanAgentTag(): bool
    {
        return in_array($this->channel, ['messenger', 'instagram'], true)
            && $this->last_inbound_at !== null
            && $this->last_inbound_at->diffInHours(now()) >= 24
            && $this->last_inbound_at->diffInDays(now()) < 7;
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'whatsapp'  => 'WhatsApp',
            'messenger' => 'Messenger',
            'instagram' => 'Instagram',
            'sms'       => 'SMS',
            'email'     => 'Email',
            default     => ucfirst($this->channel),
        };
    }

    public function channelIcon(): string
    {
        return match ($this->channel) {
            'whatsapp'  => 'bi-whatsapp',
            'messenger' => 'bi-messenger',
            'instagram' => 'bi-instagram',
            'sms'       => 'bi-chat-dots',
            'email'     => 'bi-envelope',
            default     => 'bi-chat',
        };
    }

    /**
     * Brand colour, used only as a small channel dot in the thread list — the
     * rest of the UI stays on the product palette.
     */
    public function channelColor(): string
    {
        return match ($this->channel) {
            'whatsapp'  => '#25d366',
            'messenger' => '#0084ff',
            'instagram' => '#e1306c',
            default     => 'var(--fui-ink-faint)',
        };
    }

    /**
     * Whether the CRM can open this thread, as opposed to only replying.
     * Messenger and Instagram forbid business-initiated messages outright.
     */
    public function canInitiate(): bool
    {
        return ! in_array($this->channel, ['messenger', 'instagram'], true);
    }

    /**
     * The display name for the thread header, preferring linked CRM records
     * over whatever WhatsApp reported as the profile name.
     */
    public function displayName(): string
    {
        return $this->lead?->name
            ?: ($this->patient?->name && $this->patient->name !== 'Unknown' ? $this->patient->name : null)
            ?: $this->contact_name
            ?: ($this->contact_username ? '@' . $this->contact_username : null)
            // A raw PSID is meaningless to an agent, so say what it is instead.
            ?: ($this->isSocialChannel() ? $this->channelLabel() . ' user' : $this->contact_identifier)
            ?: 'Unknown contact';
    }

    public function isSocialChannel(): bool
    {
        return in_array($this->channel, ['messenger', 'instagram'], true);
    }

    public function initials(): string
    {
        $name = trim($this->displayName());

        if ($name === '' || ! preg_match('/[A-Za-z]/', $name)) {
            return '#';
        }

        // First letter of the first two words that have one. Byte-wise substr
        // split names like "🪷🌸Sanjay Mohan" mid-emoji and broke the inbox JSON.
        preg_match_all('/\p{L}[\p{L}\p{M}]*/u', $name, $words);

        return mb_strtoupper(implode('', array_map(
            fn ($word) => mb_substr($word, 0, 1),
            array_slice($words[0], 0, 2)
        )));
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('unread_count', '>', 0);
    }

    /**
     * Agents see the threads assigned to them plus anything unclaimed;
     * managers and marketing see their center or everything.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole(['Super Admin', 'Admin', 'Management', 'Marketing Head'])) {
            return $query;
        }

        if ($user->hasRole('Center Manager')) {
            return $query->where(function (Builder $q) use ($user) {
                $q->whereHas('lead', fn ($l) => $l->where('center_id', $user->center_id))
                    ->orWhereHas('patient', fn ($p) => $p->where('center_id', $user->center_id))
                    ->orWhereNull('assigned_to');
            });
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('assigned_to', $user->id)
                ->orWhereNull('assigned_to')
                ->orWhereHas('lead', fn ($l) => $l->where('assigned_to', $user->id));
        });
    }
}
