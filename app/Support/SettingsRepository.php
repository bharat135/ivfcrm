<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Typed key/value access over the `settings` table.
 *
 * Values whose key is listed in self::SECRET_KEYS are stored encrypted at rest
 * so an app-token or app-secret is never readable from a DB dump. Reads are
 * cached per group because integration credentials are touched on every
 * outbound Graph call.
 */
class SettingsRepository
{
    private const CACHE_PREFIX = 'settings.group.';
    private const CACHE_TTL = 3600;

    /**
     * Keys stored encrypted. Anything matching these suffixes is also treated
     * as secret so new credentials are protected without editing this list.
     */
    private const SECRET_KEYS = [
        'app_secret', 'access_token', 'system_user_token', 'webhook_verify_token', 'webhook_token',
        'api_key', 'client_secret', 'private_key', 'password',
        // Google Ads: the refresh token mints access tokens indefinitely and
        // the developer token is account-wide, so both are as sensitive as a
        // password and must never sit in the clear in a database dump.
        'refresh_token', 'developer_token',
    ];

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = $this->all($group);

        return $all[$key] ?? $default;
    }

    /**
     * All key => value pairs for a group, decrypted and cast.
     */
    public function all(string $group, ?int $centerId = null): array
    {
        $cacheKey = self::CACHE_PREFIX . $group . '.' . ($centerId ?? 'global');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($group, $centerId) {
            return Setting::query()
                ->where('group', $group)
                ->where('center_id', $centerId)
                ->get()
                ->mapWithKeys(fn (Setting $s) => [$s->key => $this->decode($s)])
                ->all();
        });
    }

    public function set(string $group, string $key, mixed $value, ?int $centerId = null): void
    {
        $type = match (true) {
            is_bool($value)            => 'bool',
            is_int($value)             => 'int',
            is_array($value)           => 'json',
            default                    => 'string',
        };

        $stored = match ($type) {
            'bool'  => $value ? '1' : '0',
            'json'  => json_encode($value),
            default => (string) $value,
        };

        if ($this->isSecret($key) && $stored !== '') {
            $stored = Crypt::encryptString($stored);
        }

        Setting::updateOrCreate(
            ['center_id' => $centerId, 'group' => $group, 'key' => $key],
            ['value' => $stored, 'type' => $type],
        );

        $this->flush($group, $centerId);
    }

    /**
     * Bulk write. Blank values for secret keys are skipped so a form that
     * renders masked placeholders never wipes a stored credential.
     */
    public function setMany(array $values, string $group, ?int $centerId = null): void
    {
        foreach ($values as $key => $value) {
            if ($this->isSecret($key) && ($value === null || $value === '')) {
                continue;
            }
            $this->set($group, $key, $value, $centerId);
        }
    }

    public function forget(string $group, string $key, ?int $centerId = null): void
    {
        Setting::where('center_id', $centerId)->where('group', $group)->where('key', $key)->delete();
        $this->flush($group, $centerId);
    }

    public function flush(string $group, ?int $centerId = null): void
    {
        Cache::forget(self::CACHE_PREFIX . $group . '.' . ($centerId ?? 'global'));
    }

    public function isSecret(string $key): bool
    {
        foreach (self::SECRET_KEYS as $secret) {
            if ($key === $secret || str_ends_with($key, '_' . $secret) || str_ends_with($key, $secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Masked representation safe to echo into a settings form.
     */
    public function masked(string $group, ?int $centerId = null): array
    {
        $out = [];
        foreach ($this->all($group, $centerId) as $key => $value) {
            if ($this->isSecret($key) && is_string($value) && $value !== '') {
                $out[$key] = str_repeat('•', 12) . substr($value, -4);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    public function has(string $group, string $key, ?int $centerId = null): bool
    {
        $value = $this->all($group, $centerId)[$key] ?? null;

        return $value !== null && $value !== '';
    }

    private function decode(Setting $setting): mixed
    {
        $raw = $setting->value;

        if ($raw !== null && $raw !== '' && $this->isSecret($setting->key)) {
            try {
                $raw = Crypt::decryptString($raw);
            } catch (\Throwable $e) {
                // A value written before encryption was enabled, or a rotated
                // APP_KEY. Return it verbatim rather than breaking the page.
                Log::warning('Setting decrypt failed', ['key' => $setting->key]);
            }
        }

        return match ($setting->type) {
            'bool' => (bool) $raw,
            'int'  => (int) $raw,
            'json' => json_decode((string) $raw, true) ?: [],
            default => $raw,
        };
    }
}
