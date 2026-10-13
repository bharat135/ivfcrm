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

        // Third-party APIs may return {"error":"…"} or {"message":"…"}.
        if (is_string($error)) {
            $error = ['message' => match (true) {
                str_contains($error, 'invalid meta api version') => 'Invalid API version in the request URL. Set "App URL" to just the domain (e.g. https://crm.wapzy.org) and "API version" to e.g. v24.0.',
                str_contains($error, '(phone_number_id) does not match') => 'This Phone Number ID does not belong to the access key. Check the Phone Number ID in the provider dashboard.',
                str_contains($error, '(waba_id) does not match') => 'This WABA ID does not belong to the access key. Check the WABA ID in the provider dashboard.',
                default => $error,
            }];
        }

        $message = $error['message']
            ?? (is_string($body['message'] ?? null) ? $body['message'] : null)
            ?? ($status === 401 ? 'The API rejected the access key (401 Unauthorized).' : null)
            ?? ($status === 404 ? 'The API endpoint was not found (404). Check the App URL and API version.' : null)
            ?? 'Unknown WhatsApp API error (HTTP ' . $status . ').';

        return new self(
            message: $message,
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

        // (#100/33) "Unsupported post request. Object with ID 'x' does not exist…"
        // is what Graph says when the Phone Number ID is wrong for this token
        // or the token lacks whatsapp_business_messaging. Say that instead.
        if ($this->errorCode === 100 && $this->errorSubcode === 33
            && preg_match("/Object with ID '(\\d+)'/", $this->getMessage(), $m)) {
            return 'Meta cannot find object ' . $m[1] . ' with the saved access token. '
                . 'If this is the WhatsApp Phone Number ID, either the ID is wrong or the token lacks the '
                . 'whatsapp_business_messaging permission / WABA assignment. Run "Test connection" under '
                . 'Settings → Integrations to see which.';
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
