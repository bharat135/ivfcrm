<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A completed Flow submission, plus what the CRM did with it.
 *
 * The raw `answers` are kept verbatim even after processing: a mapping can be
 * corrected later and the submission replayed, which is not possible if only
 * the derived lead fields survive.
 */
class WhatsappFlowResponse extends Model
{
    protected $fillable = [
        'whatsapp_flow_id', 'whatsapp_flow_session_id', 'conversation_id',
        'lead_id', 'patient_id', 'message_id', 'appointment_id',
        'flow_token', 'wa_id', 'screen', 'answers', 'status', 'error', 'processed_at',
    ];

    protected $casts = [
        'answers'      => 'array',
        'processed_at' => 'datetime',
    ];

    public function flow(): BelongsTo         { return $this->belongsTo(WhatsappFlow::class, 'whatsapp_flow_id'); }
    public function session(): BelongsTo      { return $this->belongsTo(WhatsappFlowSession::class, 'whatsapp_flow_session_id'); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function lead(): BelongsTo         { return $this->belongsTo(Lead::class); }
    public function patient(): BelongsTo      { return $this->belongsTo(Patient::class); }
    public function message(): BelongsTo      { return $this->belongsTo(Message::class); }
    public function appointment(): BelongsTo  { return $this->belongsTo(Appointment::class); }

    public function scopeUnprocessed($query)
    {
        return $query->where('status', 'received');
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'processed' => 'success',
            'received'  => 'warning',
            'failed'    => 'danger',
            'ignored'   => 'secondary',
            default     => 'secondary',
        };
    }

    /**
     * A one-line summary for the response list, using whatever the flow
     * actually collected rather than a fixed set of columns.
     */
    public function summary(int $limit = 3): string
    {
        $parts = [];

        foreach ($this->answers ?? [] as $key => $value) {
            // Flow internals that are not answers.
            if (in_array($key, ['flow_token', 'version', 'screen'], true)) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = str_replace('_', ' ', $key) . ': ' . \Illuminate\Support\Str::limit((string) $value, 40);

            if (count($parts) >= $limit) {
                break;
            }
        }

        return $parts ? implode(' · ', $parts) : 'No answers recorded';
    }
}
