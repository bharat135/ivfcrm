<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelAccount extends Model
{
    use HasFactory;

    /**
     * Third-party WhatsApp API (1automations) that mirrors Meta's Cloud API
     * under its own base URL. Credentials: access_token, app_url, api_version,
     * phone_number_id, waba_id, business_id, webhook_api_url, webhook_token.
     */
    public const PROVIDER_ONEAUTOMATIONS = '1automations';

    protected $fillable = [
        'center_id',
        'channel',
        'provider',
        'name',
        'identifier',
        'credentials',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * The provider's bare domain. People paste the URL from the docs with the
     * path still on it ("https://host/api/meta" or ".../api/meta/v24.0"); the
     * CRM adds that path itself, so strip it here.
     */
    public static function normaliseProviderUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return rtrim(preg_replace('#/api/meta(/v[\d.]+)?$#i', '', $url), '/');
    }

    public function isThirdPartyWhatsApp(): bool
    {
        return $this->channel === 'whatsapp' && $this->provider === self::PROVIDER_ONEAUTOMATIONS;
    }

    /**
     * The WhatsApp account the 1automations section on Settings → Integrations
     * manages (there is one; the section creates it on first save).
     */
    public static function thirdPartyWhatsApp(): ?self
    {
        return static::where('channel', 'whatsapp')
            ->where('provider', self::PROVIDER_ONEAUTOMATIONS)
            ->orderBy('id')
            ->first();
    }
}
