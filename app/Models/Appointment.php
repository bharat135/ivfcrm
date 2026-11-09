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
        'reopen_reason',
        'reopen_requested_by',
        'reopen_requested_at',
        'reminder_sent',
    ];

    /** The outcomes that close an appointment, and that a reopen takes back. */
    public const CLOSED = ['visited', 'no_show'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'reminder_sent' => 'boolean',
            'cancelled_at' => 'datetime',
            'reopen_requested_at' => 'datetime',
        ];
    }

    /** Marked done or no-show: nothing more to do on it unless that was a mistake. */
    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    /** Someone has asked for this closed appointment to be reopened; an admin decides. */
    public function hasReopenRequest(): bool
    {
        return $this->reopen_requested_at !== null;
    }

    /**
     * Who may ask for a closed appointment to be reopened: anyone who could
     * have closed it, and whoever booked it. Whether they can see it is the
     * caller's check (isVisibleTo).
     */
    public function canRequestReopen(User $user): bool
    {
        return $user->can('reopen_appointments')
            || $user->can('appointments.complete')
            || (int) $this->created_by === (int) $user->id;
    }

    public function scopeReopenRequested(Builder $query): Builder
    {
        return $query->whereNotNull('reopen_requested_at');
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

    public function reopenRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopen_requested_by');
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
