<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One send of a Flow to one contact.
 *
 * The flow_token is the pivot of the whole feature: Meta echoes it on every
 * encrypted endpoint call and again on the final reply, and those arrive as
 * separate requests carrying no other CRM context. Without this row there is
 * no way to know which lead a submission belongs to.
 */
class WhatsappFlowSession extends Model
{
    protected $fillable = [
        'whatsapp_flow_id', 'conversation_id', 'lead_id', 'patient_id', 'message_id', 'sent_by',
        'flow_token', 'wa_id', 'status', 'current_screen', 'state',
        'sent_at', 'opened_at', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'state'        => 'array',
        'sent_at'      => 'datetime',
        'opened_at'    => 'datetime',
        'completed_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function flow(): BelongsTo         { return $this->belongsTo(WhatsappFlow::class, 'whatsapp_flow_id'); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function lead(): BelongsTo         { return $this->belongsTo(Lead::class); }
    public function patient(): BelongsTo      { return $this->belongsTo(Patient::class); }
    public function message(): BelongsTo      { return $this->belongsTo(Message::class); }
    public function sender(): BelongsTo       { return $this->belongsTo(User::class, 'sent_by'); }

    public function responses(): HasMany      { return $this->hasMany(WhatsappFlowResponse::class); }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isOpen(): bool
    {
        return ! $this->isExpired() && ! in_array($this->status, ['completed', 'failed', 'expired'], true);
    }

    /**
     * Accumulate answers across screens. An endpoint flow only sends the
     * current screen's fields, so earlier answers have to be carried here.
     */
    public function remember(array $answers): void
    {
        $this->update(['state' => array_merge($this->state ?? [], $answers)]);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'completed'   => 'success',
            'in_progress' => 'primary',
            'opened'      => 'info',
            'sent'        => 'secondary',
            'expired'     => 'secondary',
            'failed'      => 'danger',
            default       => 'secondary',
        };
    }
}
