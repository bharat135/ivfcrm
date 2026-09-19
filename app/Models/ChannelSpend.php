<?php

namespace App\Models;

use App\Support\AcquisitionChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day of ad spend on one channel.
 *
 * Deliberately channel-agnostic: Google spend arrives from the Ads API, but a
 * clinic can also type in what it paid for a radio slot and have it costed
 * against the patients it produced.
 */
class ChannelSpend extends Model
{
    protected $table = 'channel_spend';

    protected $fillable = [
        'channel',
        'date',
        'center_id',
        'campaign_id',
        'external_campaign_id',
        'external_campaign_name',
        'spend',
        'impressions',
        'clicks',
        'conversions',
        'currency',
        'is_manual',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'date'        => 'date',
            'spend'       => 'decimal:2',
            'conversions' => 'decimal:2',
            'impressions' => 'integer',
            'clicks'      => 'integer',
            'is_manual'   => 'boolean',
            'synced_at'   => 'datetime',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function channelLabel(): string
    {
        return AcquisitionChannel::label($this->channel);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeBetween($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }
}
