<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Campaign extends Model
{
    protected $fillable = [
        'lead_source_id', 'center_id', 'assign_to', 'name', 'external_id', 'external_account_id',
        'platform', 'objective', 'status', 'effective_status', 'buying_type',
        'budget', 'daily_budget', 'lifetime_budget', 'bid_strategy', 'special_ad_categories',
        'spend', 'start_date', 'end_date',
        'utm_source', 'utm_medium', 'utm_campaign', 'is_active', 'synced_at', 'sync_error',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'budget'          => 'float',
        'daily_budget'    => 'float',
        'lifetime_budget' => 'float',
        'spend'           => 'float',
        'start_date'      => 'date',
        'end_date'        => 'date',
        'special_ad_categories' => 'array',
        'synced_at'       => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    /** The agent every lead from this campaign is handed to (blank = assignment rules). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_to');
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function adsets(): HasMany
    {
        return $this->hasMany(Adset::class);
    }

    public function ads(): HasManyThrough
    {
        return $this->hasManyThrough(Ad::class, Adset::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(MetaInsight::class, 'entity_id')->where('level', 'campaign');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Campaigns that exist on Meta and can be pushed/pulled. */
    public function scopeSynced($query)
    {
        return $query->whereNotNull('external_id');
    }

    public function isMeta(): bool
    {
        return $this->platform === 'meta';
    }

    public function statusBadge(): string
    {
        return match ($this->effective_status ?: $this->status) {
            'ACTIVE'   => 'success',
            'PAUSED'   => 'warning',
            'ARCHIVED', 'DELETED' => 'secondary',
            'WITH_ISSUES', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED' => 'danger',
            default    => 'secondary',
        };
    }

    /**
     * Meta stores money in the account's minor unit (paise for INR).
     */
    public function displayBudget(): ?float
    {
        return $this->daily_budget ?: $this->lifetime_budget ?: $this->budget;
    }
}
