<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Test;

use Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory;
use Azymuthia\TurnstileBundle\Verifier\TurnstileVerifier;
use Azymuthia\TurnstileBundle\VisitorIp\ClientIpResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;

final class TurnstileMockResponseFactoryTest extends TestCase
{
    public function testATestTokenPassesForItsActionOnly(): void
    {
        $verifier = self::verifier(new TurnstileMockResponseFactory());
        $request = Request::create('http://localhost/login');

        self::assertTrue($verifier->verify(TurnstileMockResponseFactory::token('login'), 'login', $request)->isSuccess());
        self::assertSame(['action-mismatch'], $verifier->verify(TurnstileMockResponseFactory::token('login'), 'password_reset', $request)->getErrorCodes());
    }

    public function testAnythingElseIsRefusedAsCloudflareWould(): void
    {
        $verifier = self::verifier(new TurnstileMockResponseFactory());
        $request = Request::create('http://localhost/login');

        self::assertSame(['invalid-input-response'], $verifier->verify('forged', 'login', $request)->getErrorCodes());
        self::assertSame(['invalid-input-response'], $verifier->verify(TurnstileMockResponseFactory::token(''), '', $request)->getErrorCodes());
    }

    public function testAnswersWithTheConfiguredHostname(): void
    {
        $verifier = self::verifier(new TurnstileMockResponseFactory('app.example.com'));

        self::assertTrue($verifier->verify(TurnstileMockResponseFactory::token('login'), 'login', Request::create('https://app.example.com/login'))->isSuccess());
        self::assertSame(['hostname-mismatch'], $verifier->verify(TurnstileMockResponseFactory::token('login'), 'login', Request::create('http://localhost/login'))->getErrorCodes());
    }

    private static function verifier(TurnstileMockResponseFactory $factory): TurnstileVerifier
    {
        return new TurnstileVerifier(new MockHttpClient($factory), new ClientIpResolver(), new NullLogger(), 'test-secret', 'test');
    }
}
