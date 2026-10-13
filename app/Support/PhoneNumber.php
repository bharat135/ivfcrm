<?php

namespace App\Support;

/**
 * Indian phone numbers are stored as exactly ten digits: no "+91", no "91",
 * no leading "0" trunk prefix and no punctuation. Every intake path (forms,
 * Meta lead ads, imports, API, WhatsApp) goes through here, so the same
 * person always produces the same digits and dedup can rely on it.
 */
final class PhoneNumber
{
    public static function indian(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // 00 91 XXXXXXXXXX (international dialling prefix)
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // 91 XXXXXXXXXX and 0 XXXXXXXXXX
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Anything longer (e.g. "+91 022 ..." or a pasted extension) keeps
        // its last ten digits, which is the subscriber number.
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return $digits;
    }

    /** Ten digits exactly, i.e. a complete Indian subscriber number. */
    public static function isValidIndian(?string $raw): bool
    {
        $n = self::indian($raw);

        return $n !== null && strlen($n) === 10;
    }

    /**
     * The ten digits of an Indian mobile, or null when the input is anything
     * else. Strict on purpose: it is what forms and the dialler check.
     *
     * Accepted spellings all reduce to the same subscriber number:
     *   9876543210, 09876543210, 919876543210, +91 98765 43210, 0091...
     * Anything with extra digits (an extension, a foreign number, a typo) is
     * rejected rather than trimmed, and landlines (not starting 6-9) fail.
     */
    public static function mobile(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $ten = match (true) {
            strlen($digits) === 10                                 => $digits,
            strlen($digits) === 11 && str_starts_with($digits, '0')  => substr($digits, 1),
            strlen($digits) === 12 && str_starts_with($digits, '91') => substr($digits, 2),
            default                                                => null,
        };

        return $ten !== null && preg_match('/^[6-9]\d{9}$/', $ten) ? $ten : null;
    }

    /** Indian mobiles are exactly ten digits starting 6-9; landlines and foreign numbers are not. */
    public static function isIndianMobile(?string $raw): bool
    {
        return self::mobile($raw) !== null;
    }

    /** "9876543210" -> "98XXXXXX10": enough to recognise, not enough to dial. */
    public static function mask(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return $raw;
        }

        $n = preg_replace('/\D+/', '', $raw) ?? '';

        if (strlen($n) < 6) {
            return str_repeat('X', strlen($n));
        }

        return substr($n, 0, 2) . str_repeat('X', strlen($n) - 4) . substr($n, -2);
    }

    /**
     * What the current user may see: the full number for staff with the
     * view_mobile_numbers permission (and outside a request), masked for
     * telecallers. Every screen renders numbers through here.
     */
    public static function display(?string $raw): ?string
    {
        $user = auth()->user();

        if (! $user || $user->can('view_mobile_numbers')) {
            return $raw;
        }

        return self::mask($raw);
    }

    /** True when the current user may see (and dial by typing) full numbers. */
    public static function canView(): bool
    {
        $user = auth()->user();

        return ! $user || $user->can('view_mobile_numbers');
    }

    /** Hash used by lead/patient dedup, from the normalised ten digits. */
    public static function dedupHash(?string $raw): ?string
    {
        $n = self::indian($raw);

        return $n ? sha1($n) : null;
    }
}
