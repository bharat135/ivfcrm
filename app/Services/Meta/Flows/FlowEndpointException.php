<?php

namespace App\Services\Meta\Flows;

use RuntimeException;

/**
 * Raised when a decrypted endpoint request is well-formed but cannot be served
 * — an unknown screen or action, or a missing answer.
 *
 * Distinct from FlowDecryptionException: the payload arrived intact, so the
 * key pair is fine and Meta should not be told to refresh it.
 */
class FlowEndpointException extends RuntimeException
{
}
