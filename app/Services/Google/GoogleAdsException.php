<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * A Google Ads API failure, carrying enough of the response for the settings
 * screen to tell the user what to fix rather than "request failed".
 */
class GoogleAdsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $body = [],
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Google's own error enum, e.g. AUTHENTICATION_ERROR or
     * DEVELOPER_TOKEN_NOT_APPROVED. Useful for pointing at the right field.
     */
    public function errorCode(): ?string
    {
        $error = $this->body['error']['details'][0]['errors'][0]['errorCode'] ?? null;

        return $error ? (string) array_key_first($error) : null;
    }

    /**
     * True when the credentials themselves are the problem, as opposed to the
     * particular request being malformed.
     */
    public function isCredentialProblem(): bool
    {
        return in_array($this->status, [401, 403], true)
            || str_contains(strtolower($this->getMessage()), 'developer token')
            || str_contains(strtolower($this->getMessage()), 'authentication');
    }
}
