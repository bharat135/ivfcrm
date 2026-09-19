<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CycleEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'treatment_cycle_id',
        'recorded_by',
        'stage',
        'title',
        'event_date',
        'notes',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'datetime',
            'data' => 'array',
        ];
    }

    public function treatmentCycle(): BelongsTo
    {
        return $this->belongsTo(TreatmentCycle::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
