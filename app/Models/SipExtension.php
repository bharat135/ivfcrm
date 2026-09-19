<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An agent's softphone identity on the PBX.
 *
 * The secret is encrypted at rest and only ever leaves the server inside a
 * generated MicroSIP profile or when an admin explicitly reveals it.
 */
class SipExtension extends Model
{
    protected $fillable = [
        'user_id', 'center_id', 'extension', 'username', 'password', 'domain',
        'port', 'transport', 'context', 'caller_id', 'technology', 'webrtc',
        'record_calls', 'is_active', 'registration_status', 'last_registered_at', 'meta',
    ];

    protected $casts = [
        'password'           => 'encrypted',
        'webrtc'             => 'boolean',
        'record_calls'       => 'boolean',
        'is_active'          => 'boolean',
        'port'               => 'integer',
        'last_registered_at' => 'datetime',
        'meta'               => 'array',
    ];

    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::creating(function (SipExtension $sip) {
            $sip->username = $sip->username ?: $sip->extension;
            // A generated secret is safer than one an admin types twice.
            $sip->password = $sip->password ?: self::generateSecret();
        });
    }

    public static function generateSecret(int $length = 20): string
    {
        // Excludes characters that are ambiguous when read off a screen.
        return Str::password($length, letters: true, numbers: true, symbols: false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The dialable channel string, e.g. "PJSIP/1001". */
    public function channel(): string
    {
        return $this->technology . '/' . $this->extension;
    }

    public function isRegistered(): bool
    {
        return in_array($this->registration_status, ['OK', 'Not in use', 'In use', 'registered'], true);
    }

    public function registrationBadge(): string
    {
        return match (true) {
            $this->registration_status === null           => 'secondary',
            $this->isRegistered()                          => 'success',
            str_contains((string) $this->registration_status, 'Unavailable') => 'danger',
            default                                        => 'warning',
        };
    }

    /**
     * A ready-to-import MicroSIP account profile.
     *
     * MicroSIP reads an INI file with one [Account] section; dropping this next
     * to microsip.exe (or importing it) configures the softphone completely,
     * which removes the single biggest source of agent setup errors.
     */
    public function toMicroSipIni(): string
    {
        $defaults = config('telephony.microsip.defaults');
        $domain   = $this->domain ?: config('telephony.asterisk.host');
        $label    = $this->user?->name ?: ('Extension ' . $this->extension);

        $lines = [
            '[Settings]',
            'accountId=1',
            'singleMode=1',
            'enableLocalAccount=0',
            'crashReport=0',
            'STUN=' . config('telephony.microsip.stun_server'),
            'dnsSrv=0',
            'enableLog=0',
            '',
            '[Account1]',
            'label=' . $label,
            'server=' . $domain . ($this->port && $this->port !== 5060 ? ':' . $this->port : ''),
            'proxy=',
            'domain=' . $domain,
            'username=' . $this->username,
            'password=' . $this->password,
            'authID=' . $this->username,
            'displayName=' . $label,
            'transport=' . strtolower($this->transport),
            'publicAddr=',
            'srtp=' . $defaults['srtp'],
            'registerRefresh=' . $defaults['register_refresh'],
            'keepAlive=' . $defaults['keep_alive'],
            'publish=' . $defaults['publish_presence'],
            'voicemailNumber=' . $defaults['voicemail_number'],
            'dtmfMethod=' . $defaults['dtmf_method'],
            'allowRewrite=0',
            'disableSessionTimer=0',
        ];

        // MicroSIP expects CRLF and Windows-1252-safe ASCII.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * The sip: URI an agent can click to register manually.
     */
    public function sipUri(): string
    {
        return sprintf('sip:%s@%s', $this->username, $this->domain ?: config('telephony.asterisk.host'));
    }
}
