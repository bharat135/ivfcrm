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

    /**
     * Keep a doctor record in step with a CRM login that holds the Doctor
     * role, so creating the user is enough to make them bookable. Details a
     * manager has already filled in on the Doctors screen are left alone;
     * only gaps are filled from the user, and the record follows the user's
     * active flag.
     */
    public static function syncFromUser(User $user): ?self
    {
        if (! $user->hasRole('Doctor')) {
            return null;
        }

        $doctor = static::withTrashed()->firstOrNew(['user_id' => $user->id]);

        if ($doctor->trashed()) {
            $doctor->restore();
        }

        $doctor->fill([
            'name'      => $doctor->name ?: preg_replace('/^\s*dr\.?\s+/i', '', $user->name),
            'phone'     => $doctor->phone ?: $user->phone,
            'email'     => $doctor->email ?: $user->email,
            'center_id' => $doctor->center_id ?: $user->center_id,
            'is_active' => (bool) $user->is_active,
        ]);

        $doctor->save();

        return $doctor;
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
