<?php

namespace App\Models;

use App\Support\AcquisitionChannel;
use App\Support\CoupleDates;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Lead extends Model
{
    use CoupleDates, SoftDeletes;

    protected $fillable = [
        'uuid', 'name', 'mobile', 'alt_mobile', 'email', 'gender', 'age',
        'city_id', 'state_id', 'pincode',
        'lead_source_id', 'campaign_id', 'adset_id', 'ad_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'channel',
        'external_lead_id', 'center_id', 'assigned_to', 'assigned_at',
        'stage_id', 'disposition_id', 'sub_disposition_id', 'status', 'patient_id',
        'lead_date', 'first_contacted_at', 'last_activity_at', 'next_follow_up_at',
        'contact_attempts', 'score', 'score_band', 'remark', 'extra',
        'dedup_hash', 'merged_into_id',
        'wife_dob', 'husband_dob', 'marriage_date', 'trying_since',
    ];

    protected $casts = [
        'wife_dob'           => 'date',
        'husband_dob'        => 'date',
        'marriage_date'      => 'date',
        'trying_since'       => 'date',
        'lead_date'          => 'datetime',
        'assigned_at'        => 'datetime',
        'first_contacted_at' => 'datetime',
        'last_activity_at'   => 'datetime',
        'next_follow_up_at'  => 'datetime',
        'extra'              => 'array',
    ];

    /** Stored as exactly ten digits — no +91, no leading 0 — whatever was typed. */
    protected function mobile(): Attribute
    {
        return Attribute::make(set: fn ($value) => PhoneNumber::indian($value));
    }

    protected function altMobile(): Attribute
    {
        return Attribute::make(set: fn ($value) => PhoneNumber::indian($value));
    }

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            if (empty($lead->uuid)) {
                $lead->uuid = (string) Str::uuid();
            }
            if (empty($lead->dedup_hash) && $lead->mobile) {
                $lead->dedup_hash = PhoneNumber::dedupHash($lead->mobile);
            }
            if (empty($lead->channel)) {
                $lead->channel = AcquisitionChannel::detect($lead->toArray());
            }
        });
    }

    // Relationships
    public function city(): BelongsTo          { return $this->belongsTo(City::class); }
    public function state(): BelongsTo         { return $this->belongsTo(State::class); }
    public function source(): BelongsTo        { return $this->belongsTo(LeadSource::class, 'lead_source_id'); }
    public function campaign(): BelongsTo      { return $this->belongsTo(Campaign::class); }
    public function adset(): BelongsTo         { return $this->belongsTo(Adset::class); }
    public function ad(): BelongsTo            { return $this->belongsTo(Ad::class); }
    public function center(): BelongsTo        { return $this->belongsTo(Center::class); }
    public function assignedTo(): BelongsTo    { return $this->belongsTo(User::class, 'assigned_to'); }
    public function stage(): BelongsTo         { return $this->belongsTo(PipelineStage::class, 'stage_id'); }
    public function disposition(): BelongsTo   { return $this->belongsTo(Disposition::class, 'disposition_id'); }
    public function subDisposition(): BelongsTo { return $this->belongsTo(Disposition::class, 'sub_disposition_id'); }
    public function patient(): BelongsTo       { return $this->belongsTo(Patient::class); }
    public function mergedInto(): BelongsTo    { return $this->belongsTo(Lead::class, 'merged_into_id'); }

    public function followUps(): HasMany       { return $this->hasMany(FollowUp::class)->orderByDesc('scheduled_at'); }
    public function callLogs(): HasMany        { return $this->hasMany(CallLog::class)->orderByDesc('created_at'); }
    public function activities(): HasMany      { return $this->hasMany(LeadActivity::class)->orderByDesc('created_at'); }
    public function assignments(): HasMany     { return $this->hasMany(LeadAssignment::class)->orderByDesc('assigned_at'); }
    public function sourceTouches(): HasMany   { return $this->hasMany(LeadSourceTouch::class)->orderBy('occurred_at'); }

    public function tags(): MorphMany
    {
        return $this->morphMany(Taggable::class, 'taggable');
    }

    /**
     * The campaign this person came in on, by whatever name the intake path
     * left behind: the mirrored campaign, the UTM tag, the first touch, or a
     * Meta campaign that was mirrored after the lead arrived.
     */
    public function campaignLabel(): ?string
    {
        if ($this->campaign?->name) {
            return $this->campaign->name;
        }

        if (filled($this->utm_campaign)) {
            return $this->utm_campaign;
        }

        $touch = $this->sourceTouches->first(fn ($t) => $t->campaign_id || filled($t->utm_campaign));
        if ($touch) {
            return $touch->campaign?->name ?: $touch->utm_campaign;
        }

        $external = $this->extra['meta_campaign_id'] ?? null;

        return $external ? Campaign::where('external_id', $external)->value('name') : null;
    }

    /** The ad itself, when the intake path recorded one. */
    public function adLabel(): ?string
    {
        return $this->ad?->name ?: (filled($this->utm_content) ? $this->utm_content : null);
    }

    // Scopes
    public function scopeForUser($query, User $user)
    {
        if ($user->hasRole(['Super Admin', 'Management', 'Marketing Head'])) {
            return $query;
        }
        if ($user->hasRole('Center Manager')) {
            return $query->where('center_id', $user->center_id);
        }
        if ($user->can('view_all_leads')) {
            return $query;
        }
        return $query->where('assigned_to', $user->id);
    }

    public function scopePendingFollowUp($query)
    {
        return $query->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<=', now());
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['won', 'lost', 'junk']);
    }

    public function isJunk(): bool
    {
        return in_array($this->status, ['junk', 'lost']);
    }

    // -----------------------------------------------------------------
    // Attribution
    // -----------------------------------------------------------------

    public function channelLabel(): string
    {
        return AcquisitionChannel::label($this->channel);
    }

    public function channelIcon(): string
    {
        return AcquisitionChannel::icon($this->channel);
    }

    public function channelColor(): string
    {
        return AcquisitionChannel::color($this->channel);
    }

    public function isPaid(): bool
    {
        return AcquisitionChannel::isPaid($this->channel);
    }

    /**
     * The click id a conversion upload needs, and which network it belongs to.
     * Google prefers wbraid/gbraid when present because gclid is absent on the
     * iOS journeys they cover.
     *
     * @return array{network: string, key: string, value: string}|null
     */
    public function clickIdentifier(): ?array
    {
        foreach (['wbraid', 'gbraid', 'gclid'] as $key) {
            if (! empty($this->{$key})) {
                return ['network' => 'google', 'key' => $key, 'value' => $this->{$key}];
            }
        }

        if (! empty($this->fbclid)) {
            return ['network' => 'meta', 'key' => 'fbclid', 'value' => $this->fbclid];
        }

        return null;
    }

    public function scopeFromChannel($query, string|array $channel)
    {
        return $query->whereIn('channel', (array) $channel);
    }
}
