<?php

namespace App\Services\Meta\Flows;

use RuntimeException;

/**
 * Raised when a Flow endpoint request cannot be decrypted.
 *
 * `keyMismatch` is distinguished because Meta treats HTTP 421 as "refresh the
 * public key and retry", which recovers the flow. Any other failure is a plain
 * 4xx and ends the session.
 */
class FlowDecryptionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $keyMismatch = false,
    ) {
        parent::__construct($message);
    }

    /**
     * 421 tells the WhatsApp client to re-fetch the business public key.
     */
    public function httpStatus(): int
    {
        return $this->keyMismatch ? 421 : 432;
    }
}
