<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * A login refused by Turnstile. The message is the same whether the check failed or Cloudflare
 * could not be reached; the error codes tell them apart for a login-failure listener to log.
 */
final class TurnstileAuthenticationException extends CustomUserMessageAuthenticationException
{
    public const string MESSAGE_KEY = 'Verification failed. Refresh the page and try again.';

    /**
     * @param list<string> $errorCodes
     */
    public function __construct(
        private array $errorCodes = [],
    ) {
        parent::__construct(self::MESSAGE_KEY);
    }

    /**
     * @return list<string>
     */
    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }

    /**
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        return [parent::__serialize(), $this->errorCodes];
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        [$parentData, $this->errorCodes] = $data;
        parent::__unserialize($parentData);
    }
}
