<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LandingPage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'slug', 'name', 'title', 'headline', 'subheadline',
        'hero_image', 'logo_image', 'template', 'theme_color',
        'body_html', 'custom_css', 'sections', 'form_fields', 'cta_text',
        'thank_you_message', 'redirect_url', 'whatsapp_number',
        'lead_source_id', 'campaign_id', 'center_id', 'assign_to', 'whatsapp_template_id',
        'meta_pixel_id', 'google_analytics_id', 'seo',
        'is_published', 'views', 'submissions', 'published_at', 'created_by',
    ];

    protected $casts = [
        'sections'     => 'array',
        'form_fields'  => 'array',
        'seo'          => 'array',
        'is_published' => 'boolean',
        'views'        => 'integer',
        'submissions'  => 'integer',
        'published_at' => 'datetime',
    ];

    /**
     * The form builder's default field set — a fertility-clinic enquiry form.
     */
    public const DEFAULT_FORM_FIELDS = [
        ['key' => 'name',   'label' => 'Your Name',        'type' => 'text',   'required' => true],
        ['key' => 'mobile', 'label' => 'Mobile Number',    'type' => 'tel',    'required' => true],
        ['key' => 'email',  'label' => 'Email Address',    'type' => 'email',  'required' => false],
        ['key' => 'city',   'label' => 'City',             'type' => 'text',   'required' => false],
        ['key' => 'age',    'label' => 'Age',              'type' => 'number', 'required' => false],
        ['key' => 'message','label' => 'Tell us briefly',  'type' => 'textarea','required' => false],
    ];

    protected static function booted(): void
    {
        static::creating(function (LandingPage $page) {
            $page->uuid = $page->uuid ?: (string) Str::uuid();
            $page->slug = $page->slug ?: Str::slug($page->name) . '-' . Str::lower(Str::random(4));
        });
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

    public function welcomeTemplate(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'whatsapp_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submissionRecords(): HasMany
    {
        return $this->hasMany(LandingPageSubmission::class);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function publicUrl(): string
    {
        return url('/lp/' . $this->slug);
    }

    public function fields(): array
    {
        return $this->form_fields ?: self::DEFAULT_FORM_FIELDS;
    }

    public function conversionRate(): float
    {
        return $this->views > 0 ? round(($this->submissions / $this->views) * 100, 2) : 0.0;
    }
}
