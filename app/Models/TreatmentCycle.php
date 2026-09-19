<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentCycle extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'center_id',
        'cycle_code',
        'type',
        'cycle_number',
        'current_stage',
        'status',
        'started_on',
        'ended_on',
        'package_amount',
        'paid_amount',
        'outcome',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'cycle_number' => 'integer',
            'started_on' => 'date',
            'ended_on' => 'date',
            'package_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function cycleEvents(): HasMany
    {
        return $this->hasMany(CycleEvent::class);
    }
}
