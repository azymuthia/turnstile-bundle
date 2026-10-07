<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Security;

use Azymuthia\TurnstileBundle\Security\TurnstileAuthenticationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

final class TurnstileAuthenticationExceptionTest extends TestCase
{
    public function testCarriesOneTranslatableMessageAndTheErrorCodes(): void
    {
        $exception = new TurnstileAuthenticationException(['unreachable']);

        self::assertInstanceOf(CustomUserMessageAuthenticationException::class, $exception);
        self::assertSame('Verification failed. Refresh the page and try again.', $exception->getMessageKey());
        self::assertSame([], $exception->getMessageData());
        self::assertSame(['unreachable'], $exception->getErrorCodes());
    }

    public function testKeepsTheErrorCodesThroughTheSession(): void
    {
        // The security layer stores the last authentication error in the session, serialized.
        $exception = unserialize(serialize(new TurnstileAuthenticationException(['timeout-or-duplicate'])));

        self::assertInstanceOf(TurnstileAuthenticationException::class, $exception);
        self::assertSame(['timeout-or-duplicate'], $exception->getErrorCodes());
        self::assertSame('Verification failed. Refresh the page and try again.', $exception->getMessageKey());
    }
}
