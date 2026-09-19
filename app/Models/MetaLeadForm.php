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
        'auto_sync', 'leads_count', 'last_synced_at', 'leads_synced_at',
    ];

    protected $casts = [
        'questions'      => 'array',
        'field_map'      => 'array',
        'auto_sync'      => 'boolean',
        'leads_count'    => 'integer',
        'last_synced_at' => 'datetime',
        'leads_synced_at' => 'datetime',
    ];

    /**
     * Meta's standard question keys mapped onto CRM lead attributes. A form's
     * own `field_map` overrides these for custom questions.
     */
    public const DEFAULT_FIELD_MAP = [
        'full_name'       => 'name',
        'first_name'      => 'name',
        'name'            => 'name',
        'email'           => 'email',
        'phone_number'    => 'mobile',
        'phone'           => 'mobile',
        'mobile'          => 'mobile',
        'mobile_number'   => 'mobile',
        'contact_number'  => 'mobile',
        'whatsapp_number' => 'mobile',
        'city'            => 'city_name',
        'state'           => 'state_name',
        'gender'          => 'gender',
        'date_of_birth'   => 'dob',
        'street_address'  => 'address',
        'post_code'       => 'pincode',
        'zip_code'        => 'pincode',
        'pincode'         => 'pincode',
    ];

    /**
     * Custom questions are keyed by their (often Marathi / Hindi) wording, so
     * an unmapped key is matched on what it obviously asks for. Only used
     * when neither the defaults nor the form's own field_map name the key.
     */
    public static function guessTarget(string $key): ?string
    {
        $k = mb_strtolower($key);

        $rules = [
            'mobile'       => ['phone', 'mobile', 'contact', 'whatsapp', 'फोन', 'मोबाइल', 'मोबाईल', 'संपर्क', 'नंबर'],
            'email'        => ['email', 'e-mail', 'ईमेल'],
            'years_trying' => ['trying', 'conceive', 'pregnan', 'प्रयत्न', 'प्रेग्नेंसी', 'गर्भ', 'प्रयास'],
            'name'         => ['name', 'नाव', 'नाम'],
            'city'         => ['city', 'शहर', 'गाव', 'location', 'area'],
            'wife_age'     => ['age', 'वय', 'उम्र', 'आयु'],
            'message'      => ['message', 'query', 'question', 'comment', 'संदेश'],
        ];

        foreach ($rules as $target => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($k, $needle)) {
                    return $target === 'city' ? 'city_name' : $target;
                }
            }
        }

        return null;
    }

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
