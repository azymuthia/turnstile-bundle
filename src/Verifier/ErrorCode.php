<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Verifier;

/**
 * Error codes of a refused verification besides Cloudflare's own `error-codes`, which are passed through.
 */
final class ErrorCode
{
    /** Siteverify timed out, could not be reached, answered non-2xx or answered something that is not a siteverify response. */
    public const string UNREACHABLE = 'unreachable';

    /** Cloudflare's code: the secret is empty, or is a dummy secret used in `prod`. */
    public const string INVALID_INPUT_SECRET = 'invalid-input-secret';

    /** Cloudflare's code: no token was posted. */
    public const string MISSING_INPUT_RESPONSE = 'missing-input-response';

    /** Cloudflare's code: the token is longer than any Turnstile token. */
    public const string INVALID_INPUT_RESPONSE = 'invalid-input-response';

    /** The token was issued on a hostname that is neither the request host nor an allowed hostname. */
    public const string HOSTNAME_MISMATCH = 'hostname-mismatch';

    /** The token was issued for a different action. */
    public const string ACTION_MISMATCH = 'action-mismatch';

    private function __construct()
    {
    }
}
