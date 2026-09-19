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

    /** Indian mobiles start with 6-9; landlines do not. */
    public static function isIndianMobile(?string $raw): bool
    {
        return self::isValidIndian($raw) && (bool) preg_match('/^[6-9]\d{9}$/', self::indian($raw));
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
