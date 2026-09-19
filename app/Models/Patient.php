<?php

namespace App\Models;

use App\Support\CoupleDates;
use App\Support\PatientJourney;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use CoupleDates, HasFactory, SoftDeletes;

    protected $fillable = [
        'patient_code',
        'lead_id',
        'center_id',
        'primary_doctor_id',
        'wife_name',
        'husband_name',
        'mobile',
        'alt_mobile',
        'email',
        'wife_dob',
        'husband_dob',
        'wife_age',
        'husband_age',
        'marriage_date',
        'trying_since',
        'address',
        'city_id',
        'state_id',
        'pincode',
        'infertility_duration_months',
        'previous_ivf',
        'diagnosis',
        'treatment_notes',
        'status',
        'journey_stage',
        'first_enquiry_at',
        'dedup_hash',
        'meta',
    ];

    protected static function booted(): void
    {
        static::creating(function (Patient $patient) {
            if (empty($patient->patient_code)) {
                $patient->patient_code = 'PAT-' . str_pad(
                    (Patient::withTrashed()->max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT
                );
            }

            // The same normalisation leads use, so one person cannot end up
            // with a lead row and an unrelated patient row for one number.
            if (empty($patient->dedup_hash) && $patient->mobile) {
                $patient->dedup_hash = sha1(preg_replace('/\D/', '', $patient->mobile));
            }

            $patient->first_enquiry_at = $patient->first_enquiry_at ?: now();
        });
    }

    public function getNameAttribute(): string
    {
        return $this->wife_name ?: ($this->husband_name ?: 'Unknown');
    }

    /**
     * A patient and a lead are the same person; this is how the record reads
     * when the couple is shown as one line.
     */
    public function coupleName(): string
    {
        $names = array_filter([$this->wife_name, $this->husband_name]);

        return $names === [] ? 'Unknown' : implode(' & ', $names);
    }

    public function stageLabel(): string
    {
        return PatientJourney::label($this->journey_stage);
    }

    public function stageColor(): string
    {
        return PatientJourney::color($this->journey_stage);
    }

    public function stageIcon(): string
    {
        return PatientJourney::icon($this->journey_stage);
    }

    /**
     * True once the couple has actually started treatment, as opposed to being
     * a patient file that only exists because an enquiry came in.
     */
    public function isClinicallyActive(): bool
    {
        return in_array($this->journey_stage, [PatientJourney::TREATMENT, PatientJourney::CLOSED], true);
    }

    /** Where this person came from, read off the acquisition facet. */
    public function acquisitionChannel(): string
    {
        return $this->lead?->channel ?: 'manual';
    }

    protected function casts(): array
    {
        return [
            'wife_dob' => 'date',
            'husband_dob' => 'date',
            'marriage_date' => 'date',
            'trying_since' => 'date',
            'wife_age' => 'integer',
            'husband_age' => 'integer',
            'infertility_duration_months' => 'integer',
            'previous_ivf' => 'boolean',
            'first_enquiry_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function primaryDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'primary_doctor_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class);
    }

    public function patientDocuments(): HasMany
    {
        return $this->documents();
    }

    public function treatmentCycles(): HasMany
    {
        return $this->hasMany(TreatmentCycle::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function counsellingSessions(): HasMany
    {
        return $this->hasMany(CounsellingSession::class);
    }

    // -----------------------------------------------------------------
    // The acquisition facet
    // -----------------------------------------------------------------

    /**
     * Conversations, calls, follow-ups and activities are recorded against the
     * lead facet, so the patient screen reaches them through it.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function followUps()
    {
        return $this->hasManyThrough(FollowUp::class, Lead::class, 'patient_id', 'lead_id');
    }

    public function callLogs()
    {
        return $this->hasManyThrough(CallLog::class, Lead::class, 'patient_id', 'lead_id');
    }

    public function activities()
    {
        return $this->hasManyThrough(LeadActivity::class, Lead::class, 'patient_id', 'lead_id');
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeInStage($query, string|array $stage)
    {
        return $query->whereIn('journey_stage', (array) $stage);
    }

    /**
     * Mirrors Lead::scopeForUser so the unified people list obeys the same
     * visibility rules whichever surface it is viewed from.
     */
    public function scopeForUser($query, User $user)
    {
        if ($user->hasRole(['Super Admin', 'Admin', 'Management', 'Marketing Head'])) {
            return $query;
        }

        if ($user->hasRole('Center Manager')) {
            return $query->where('center_id', $user->center_id);
        }

        return $query->whereHas('lead', fn ($q) => $q->where('assigned_to', $user->id));
    }
}
