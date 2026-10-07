<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * The value is a Turnstile token issued for the action on the current request's host.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Turnstile extends Constraint
{
    /** One message whether the check failed or Cloudflare could not be reached; it does not say which. */
    public string $message = 'Verification failed. Refresh the page and try again.';

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        public string $action,
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
