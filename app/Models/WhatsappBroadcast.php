<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappBroadcast extends Model
{
    protected $fillable = [
        'name', 'channel_account_id', 'whatsapp_template_id', 'center_id', 'created_by',
        'audience_type', 'filters', 'variable_map', 'status',
        'total_recipients', 'sent_count', 'delivered_count', 'read_count', 'failed_count',
        'scheduled_at', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'filters'      => 'array',
        'variable_map' => 'array',
        'scheduled_at' => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id');
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(WhatsappBroadcastRecipient::class);
    }

    public function progressPercent(): int
    {
        if (! $this->total_recipients) {
            return 0;
        }

        return (int) round((($this->sent_count + $this->failed_count) / $this->total_recipients) * 100);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }
}
