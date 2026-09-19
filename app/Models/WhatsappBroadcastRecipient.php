<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappBroadcastRecipient extends Model
{
    protected $fillable = [
        'whatsapp_broadcast_id', 'lead_id', 'patient_id', 'message_id',
        'mobile', 'name', 'variables', 'status', 'error', 'sent_at',
    ];

    protected $casts = [
        'variables' => 'array',
        'sent_at'   => 'datetime',
    ];

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(WhatsappBroadcast::class, 'whatsapp_broadcast_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
