<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaLeadForm extends Model
{
    protected $fillable = [
        'external_id', 'page_id', 'page_name', 'name', 'status', 'locale',
        'questions', 'field_map', 'privacy_policy_url', 'follow_up_action_url',
        'lead_source_id', 'campaign_id', 'center_id', 'assign_to',
        'auto_sync', 'leads_count', 'last_synced_at',
    ];

    protected $casts = [
        'questions'      => 'array',
        'field_map'      => 'array',
        'auto_sync'      => 'boolean',
        'leads_count'    => 'integer',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Meta's standard question keys mapped onto CRM lead attributes. A form's
     * own `field_map` overrides these for custom questions.
     */
    public const DEFAULT_FIELD_MAP = [
        'full_name'    => 'name',
        'first_name'   => 'name',
        'email'        => 'email',
        'phone_number' => 'mobile',
        'city'         => 'city_name',
        'state'        => 'state_name',
        'gender'       => 'gender',
        'date_of_birth'=> 'dob',
        'street_address' => 'address',
        'post_code'    => 'pincode',
        'zip_code'     => 'pincode',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assign_to');
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    /**
     * Effective mapping: form-specific overrides on top of the defaults.
     */
    public function effectiveFieldMap(): array
    {
        return array_merge(self::DEFAULT_FIELD_MAP, $this->field_map ?? []);
    }
}
