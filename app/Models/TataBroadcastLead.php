<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TataBroadcastLead extends Model
{
    protected $fillable = [
        'tata_broadcast_id', 'lead_id', 'mobile', 'status', 'dial_status', 'attempts', 'call_log_id', 'last_event',
    ];

    protected $casts = ['last_event' => 'array'];

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(TataBroadcast::class, 'tata_broadcast_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }
}
