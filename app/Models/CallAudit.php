<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_log_id',
        'lead_id',
        'analyst_id',
        'source',
        'transcript',
        'language',
        'sentiment',
        'sentiment_score',
        'quality_score',
        'scorecard',
        'flags',
        'summary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sentiment_score' => 'decimal:2',
            'quality_score' => 'integer',
            'scorecard' => 'array',
            'flags' => 'array',
        ];
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function analyst(): BelongsTo
    {
        return $this->belongsTo(User::class, 'analyst_id');
    }
}
