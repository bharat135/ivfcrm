<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'intent',
        'budget',
        'urgency',
        'stage_fit',
        'total_score',
        'band',
        'model_version',
        'factors',
    ];

    protected function casts(): array
    {
        return [
            'intent' => 'integer',
            'budget' => 'integer',
            'urgency' => 'integer',
            'stage_fit' => 'integer',
            'total_score' => 'integer',
            'factors' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
