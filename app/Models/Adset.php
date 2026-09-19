<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Adset extends Model
{
    protected $fillable = [
        'campaign_id', 'name', 'external_id', 'status', 'effective_status',
        'daily_budget', 'lifetime_budget', 'optimization_goal', 'billing_event',
        'bid_amount', 'targeting', 'promoted_object', 'destination_type',
        'start_time', 'end_time', 'spend', 'synced_at', 'sync_error',
    ];

    protected $casts = [
        'spend'           => 'float',
        'daily_budget'    => 'float',
        'lifetime_budget' => 'float',
        'bid_amount'      => 'float',
        'targeting'       => 'array',
        'promoted_object' => 'array',
        'start_time'      => 'datetime',
        'end_time'        => 'datetime',
        'synced_at'       => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(MetaInsight::class, 'entity_id')->where('level', 'adset');
    }

    public function statusBadge(): string
    {
        return match ($this->effective_status ?: $this->status) {
            'ACTIVE' => 'success',
            'PAUSED', 'CAMPAIGN_PAUSED' => 'warning',
            'ARCHIVED', 'DELETED' => 'secondary',
            default  => 'secondary',
        };
    }

    /**
     * Human-readable summary of the targeting JSON for the list view.
     */
    public function targetingSummary(): string
    {
        $t = $this->targeting ?? [];
        $parts = [];

        $ageMin = $t['age_min'] ?? null;
        $ageMax = $t['age_max'] ?? null;
        if ($ageMin || $ageMax) {
            $parts[] = 'Age ' . ($ageMin ?? 18) . '-' . ($ageMax ?? 65);
        }

        $genders = $t['genders'] ?? [];
        if ($genders === [1]) {
            $parts[] = 'Men';
        } elseif ($genders === [2]) {
            $parts[] = 'Women';
        }

        $locations = $t['geo_locations']['cities'] ?? [];
        if ($locations) {
            $names = array_slice(array_column($locations, 'name'), 0, 3);
            $parts[] = implode(', ', array_filter($names));
            if (count($locations) > 3) {
                $parts[] = '+' . (count($locations) - 3) . ' more';
            }
        } elseif (! empty($t['geo_locations']['countries'])) {
            $parts[] = implode(', ', $t['geo_locations']['countries']);
        }

        $interests = $t['flexible_spec'][0]['interests'] ?? [];
        if ($interests) {
            $parts[] = count($interests) . ' interest' . (count($interests) > 1 ? 's' : '');
        }

        return $parts ? implode(' · ', $parts) : 'Broad';
    }
}
