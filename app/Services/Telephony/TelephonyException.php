<?php

namespace App\Services\Telephony;

use RuntimeException;

/**
 * Base class for every calling-provider failure (Asterisk AMI, Tata Smartflo).
 *
 * The dialer only ever shows displayMessage(), so each provider maps its raw
 * error into something an agent can act on before throwing.
 */
class TelephonyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerMessage = null,
        public readonly array $raw = [],
    ) {
        parent::__construct($message);
    }

    public function displayMessage(): string
    {
        return $this->providerMessage ?: $this->getMessage();
    }

    /**
     * Connection-level problems are worth retrying; a rejected request is not.
     */
    public function isConnectionError(): bool
    {
        return $this->providerMessage === null;
    }
}
