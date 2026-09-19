<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ad extends Model
{
    protected $fillable = [
        'adset_id', 'name', 'external_id', 'status', 'effective_status',
        'external_creative_id', 'creative', 'preview_url', 'thumbnail_url',
        'meta_lead_form_id', 'spend', 'synced_at', 'sync_error',
    ];

    protected $casts = [
        'spend'     => 'float',
        'creative'  => 'array',
        'synced_at' => 'datetime',
    ];

    public function adset(): BelongsTo
    {
        return $this->belongsTo(Adset::class);
    }

    public function leadForm(): BelongsTo
    {
        return $this->belongsTo(MetaLeadForm::class, 'meta_lead_form_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(MetaInsight::class, 'entity_id')->where('level', 'ad');
    }

    public function statusBadge(): string
    {
        return match ($this->effective_status ?: $this->status) {
            'ACTIVE' => 'success',
            'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED' => 'warning',
            'ARCHIVED', 'DELETED' => 'secondary',
            'DISAPPROVED', 'WITH_ISSUES' => 'danger',
            'PENDING_REVIEW', 'IN_PROCESS' => 'info',
            default  => 'secondary',
        };
    }

    public function primaryText(): ?string
    {
        return $this->creative['object_story_spec']['link_data']['message']
            ?? $this->creative['body']
            ?? null;
    }

    public function headline(): ?string
    {
        return $this->creative['object_story_spec']['link_data']['name']
            ?? $this->creative['title']
            ?? null;
    }
}
