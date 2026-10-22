<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'doctor_id',
        'center_id',
        'lead_id',
        'patient_id',
        'created_by',
        'type',
        'scheduled_at',
        'duration_minutes',
        'status',
        'rescheduled_from_id',
        'notes',
        'cancel_reason',
        'cancelled_by',
        'cancelled_at',
        'reminder_sent',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'reminder_sent' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The appointments a user may see: every one (appointments.view_all), their
     * centre's (appointments.view_center) or only the ones they booked
     * (appointments.view_own, and the fallback). A doctor always sees their own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('appointments.view_all')) {
            return $query;
        }

        $doctorId = $user->doctor?->id;

        return $query->where(function (Builder $q) use ($user, $doctorId) {
            if ($user->can('appointments.view_center') && $user->center_id) {
                $q->where('center_id', $user->center_id);
            } else {
                $q->where('created_by', $user->id);
            }

            if ($doctorId) {
                $q->orWhere('doctor_id', $doctorId);
            }
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'rescheduled_from_id');
    }
}
