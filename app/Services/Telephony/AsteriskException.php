<?php

namespace App\Services\Telephony;

/**
 * Raised when AMI is unreachable, rejects the login, or returns an Error
 * response. Carries a message safe to show an agent in the dialer.
 */
class AsteriskException extends TelephonyException
{
    public function __construct(
        string $message,
        public readonly ?string $amiMessage = null,
        array $raw = [],
    ) {
        parent::__construct($message, $amiMessage, $raw);
    }
}
