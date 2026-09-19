<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (level, external_id, date). Populated by SyncMetaInsightsJob so
 * dashboards read from MySQL instead of hitting the Graph API on page load.
 */
class MetaInsight extends Model
{
    protected $fillable = [
        'level', 'external_id', 'entity_id', 'date',
        'impressions', 'reach', 'clicks', 'unique_clicks', 'spend',
        'frequency', 'ctr', 'cpc', 'cpm', 'leads', 'cost_per_lead',
        'messaging_conversations', 'landing_page_views', 'actions',
    ];

    protected $casts = [
        'date'         => 'date',
        'impressions'  => 'integer',
        'reach'        => 'integer',
        'clicks'       => 'integer',
        'unique_clicks'=> 'integer',
        'leads'        => 'integer',
        'messaging_conversations' => 'integer',
        'landing_page_views'      => 'integer',
        'spend'        => 'float',
        'frequency'    => 'float',
        'ctr'          => 'float',
        'cpc'          => 'float',
        'cpm'          => 'float',
        'cost_per_lead'=> 'float',
        'actions'      => 'array',
    ];

    public function scopeLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    public function scopeBetween($query, $from, $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    /**
     * Pull a numeric total out of Meta's `actions` array by action type.
     */
    public static function actionValue(?array $actions, string $type): float
    {
        foreach ($actions ?? [] as $action) {
            if (($action['action_type'] ?? null) === $type) {
                return (float) ($action['value'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * Meta reports leads under several action types depending on destination.
     */
    public static function leadCount(?array $actions): int
    {
        $types = [
            'lead',
            'onsite_conversion.lead_grouped',
            'leadgen.other',
            'offsite_conversion.fb_pixel_lead',
            'onsite_conversion.messaging_conversation_started_7d',
        ];

        foreach ($types as $type) {
            $value = self::actionValue($actions, $type);
            if ($value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }
}
