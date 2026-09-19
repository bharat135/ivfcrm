<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'direction',
        'is_echo',
        'type',
        'media_type',
        'template_name',
        'body',
        'provider_message_id',
        'reply_to_message_id',
        'status',
        'error_code',
        'error_message',
        'sent_at',
        'is_internal_note',
        'delivered_at',
        'failed_at',
        'read_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
            'is_echo'          => 'boolean',
            'sent_at'          => 'datetime',
            'delivered_at'     => 'datetime',
            'failed_at'        => 'datetime',
            'read_at'          => 'datetime',
            'meta'             => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messageAttachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /** Alias so views can read `$message->attachments`. */
    public function attachments(): HasMany
    {
        return $this->messageAttachments();
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
    }

    /**
     * Bootstrap icon + colour for the WhatsApp-style delivery ticks.
     */
    public function statusIcon(): array
    {
        return match ($this->status) {
            'queued'    => ['bi-clock', 'text-muted'],
            'sent'      => ['bi-check', 'text-muted'],
            'delivered' => ['bi-check-all', 'text-muted'],
            'read'      => ['bi-check-all', 'text-primary'],
            'failed'    => ['bi-exclamation-circle', 'text-danger'],
            default     => ['bi-clock', 'text-muted'],
        };
    }

    /**
     * A one-line summary for the conversation list.
     */
    public function preview(int $length = 60): string
    {
        if ($this->is_internal_note) {
            return '📝 ' . \Illuminate\Support\Str::limit(strip_tags((string) $this->body), $length);
        }

        if ($this->media_type === 'flow' || ($this->meta['interactive']['type'] ?? null) === 'nfm_reply') {
            return '📋 ' . \Illuminate\Support\Str::limit((string) ($this->body ?: 'WhatsApp form'), $length);
        }

        if ($this->type === 'template') {
            return '📄 ' . \Illuminate\Support\Str::limit((string) ($this->body ?: $this->template_name), $length);
        }

        if ($this->media_type) {
            $label = match ($this->media_type) {
                'image'    => '📷 Photo',
                'video'    => '🎥 Video',
                'audio'    => '🎤 Voice message',
                'document' => '📎 Document',
                'sticker'  => '🌟 Sticker',
                'location' => '📍 Location',
                'contacts' => '👤 Contact',
                default    => '📎 Attachment',
            };

            return $this->body ? $label . ' · ' . \Illuminate\Support\Str::limit($this->body, $length) : $label;
        }

        return \Illuminate\Support\Str::limit(strip_tags((string) $this->body), $length) ?: '—';
    }
}
