<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssignmentRule extends Model
{
    protected $fillable = [
        'name', 'method', 'priority', 'conditions', 'targets', 'is_active', 'leads_count',
    ];

    protected $casts = [
        'conditions'  => 'array',
        'targets'     => 'array',
        'is_active'   => 'boolean',
        'leads_count' => 'integer',
    ];

    /** Condition keys the engine understands, in display order. */
    public const CONDITION_KEYS = [
        'center_id', 'lead_source_id', 'campaign_id', 'channel', 'state_id', 'pincode_prefix',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('priority')->orderBy('id');
    }

    /** @return int[] */
    public function userIds(): array
    {
        return array_values(array_map('intval', $this->targets['user_ids'] ?? []));
    }

    /** Only the conditions that actually constrain something. */
    public function activeConditions(): array
    {
        return array_filter($this->conditions ?? [], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
