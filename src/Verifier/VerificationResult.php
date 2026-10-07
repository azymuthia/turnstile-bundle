<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Verifier;

final readonly class VerificationResult
{
    /**
     * @param list<string> $errorCodes
     */
    private function __construct(
        private bool $success,
        private array $errorCodes,
    ) {
    }

    public static function success(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $errorCodes
     */
    public static function failure(array $errorCodes): self
    {
        return new self(false, $errorCodes);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Cloudflare's `error-codes` (sanitized) or one of {@see ErrorCode}; empty on success.
     *
     * @return list<string>
     */
    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }
}
