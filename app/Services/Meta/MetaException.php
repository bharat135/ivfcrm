<?php

namespace App\Services\Meta;

use RuntimeException;

/**
 * Wraps a Graph API error so callers get the Meta error code/subcode and a
 * message that is safe to surface in the UI.
 */
class MetaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly ?int $errorSubcode = null,
        public readonly ?string $errorType = null,
        public readonly ?string $traceId = null,
        public readonly ?string $userTitle = null,
        public readonly ?string $userMessage = null,
        public readonly array $raw = [],
        int $httpStatus = 0,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function fromResponse(array $body, int $status): self
    {
        $error = $body['error'] ?? [];

        return new self(
            message: $error['message'] ?? 'Unknown Meta Graph API error.',
            errorCode: isset($error['code']) ? (int) $error['code'] : null,
            errorSubcode: isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            errorType: $error['type'] ?? null,
            traceId: $error['fbtrace_id'] ?? null,
            userTitle: $error['error_user_title'] ?? null,
            userMessage: $error['error_user_msg'] ?? null,
            raw: $body,
            httpStatus: $status,
        );
    }

    /**
     * The friendliest available wording for an end user.
     */
    public function displayMessage(): string
    {
        if ($this->userMessage) {
            return trim(($this->userTitle ? $this->userTitle . ': ' : '') . $this->userMessage);
        }

        return $this->getMessage();
    }

    /**
     * Whether retrying the same call could plausibly succeed.
     * 1 = unknown, 2 = temporary, 4/17/32/613 = rate limits, 341 = app limit.
     */
    public function isTransient(): bool
    {
        return in_array($this->errorCode, [1, 2, 4, 17, 32, 341, 613], true)
            || $this->getCode() >= 500;
    }

    /**
     * 190 = invalid/expired token, 102 = session, 200/10/3 = permission.
     */
    public function isAuthError(): bool
    {
        return in_array($this->errorCode, [190, 102, 200, 10, 3], true);
    }
}
