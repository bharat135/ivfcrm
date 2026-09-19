<?php

namespace App\Services\Telephony;

/**
 * Raised when the Tata Tele Business Services (Smartflo) API is unreachable,
 * rejects the credentials, or refuses a click-to-call request.
 */
class TataException extends TelephonyException
{
    public static function fromResponse(string $fallback, array $body, int $status = 0): self
    {
        $raw = $body['message'] ?? $body['error'] ?? $body['errors'] ?? '';
        $message = trim(self::flatten($raw));

        return new self(
            $message !== '' ? $message : $fallback,
            $message !== '' ? $message : null,
            ['status' => $status] + $body,
        );
    }

    /** Smartflo errors may be a string, or an array/object of field messages. */
    private static function flatten(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, function ($v) use (&$parts) {
                if (is_scalar($v) && trim((string) $v) !== '') {
                    $parts[] = (string) $v;
                }
            });

            return implode('. ', array_unique($parts));
        }

        return '';
    }
}
