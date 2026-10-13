<?php

namespace App\Models;

use App\Support\AcquisitionChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One acquisition touch for a lead: the channel, source, and campaign a given
 * enquiry arrived through. A lead accumulates one row per enquiry so a repeat
 * enquiry from a different channel is tracked rather than lost.
 */
class LeadSourceTouch extends Model
{
    protected $fillable = [
        'lead_id', 'channel', 'lead_source_id', 'campaign_id', 'adset_id', 'ad_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'external_lead_id', 'is_first', 'occurred_at', 'raw',
    ];

    protected function casts(): array
    {
        return [
            'is_first'    => 'boolean',
            'occurred_at' => 'datetime',
            'raw'         => 'array',
        ];
    }

    public function lead(): BelongsTo       { return $this->belongsTo(Lead::class); }
    public function source(): BelongsTo     { return $this->belongsTo(LeadSource::class, 'lead_source_id'); }
    public function campaign(): BelongsTo   { return $this->belongsTo(Campaign::class); }

    public function channelLabel(): string { return AcquisitionChannel::label($this->channel ?: 'manual'); }
    public function channelIcon(): string  { return AcquisitionChannel::icon($this->channel ?: 'manual'); }
    public function channelColor(): string { return AcquisitionChannel::color($this->channel ?: 'manual'); }
}
