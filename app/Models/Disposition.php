<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Disposition extends Model
{
    protected $fillable = [
        'parent_id', 'name', 'slug', 'requires_remark', 'requires_followup',
        'is_won', 'is_lost', 'moves_to_stage_id', 'sort_order', 'color', 'is_active',
    ];

    protected $casts = [
        'requires_remark'   => 'boolean',
        'requires_followup' => 'boolean',
        'is_won'            => 'boolean',
        'is_lost'           => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Disposition::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Disposition::class, 'parent_id')->orderBy('sort_order');
    }

    public function movesToStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'moves_to_stage_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * "Visit done" and "Already visit done": the patient has been to the
     * clinic, so there is no next calling time to pick. Matched by the stage
     * the disposition moves the lead to (consultation visited) or its slug,
     * since the live list is edited under Settings.
     */
    public function isVisitOutcome(): bool
    {
        static $visitStageId = false;
        if ($visitStageId === false) {
            $visitStageId = PipelineStage::where('slug', 'consultation-visited')->value('id');
        }

        return in_array($this->slug, ['visit-done', 'already-visit-done', 'appointment-visited'], true)
            || ($visitStageId && (int) $this->moves_to_stage_id === (int) $visitStageId);
    }

    /** Whether saving this outcome needs a next follow-up time. */
    public function needsFollowUpTime(): bool
    {
        return ! $this->is_lost && ! $this->isVisitOutcome();
    }

    public function scopePrimary($query)
    {
        return $query->whereNull('parent_id');
    }
}
