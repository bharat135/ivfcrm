<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Doctor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'center_id',
        'name',
        'code',
        'qualification',
        'specialization',
        'phone',
        'email',
        'consultation_minutes',
        'consultation_fee',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'consultation_minutes' => 'integer',
            'consultation_fee' => 'decimal:2',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function doctorSchedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    public function treatmentCycles(): HasMany
    {
        return $this->hasMany(TreatmentCycle::class);
    }
}
