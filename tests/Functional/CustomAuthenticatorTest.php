<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional;

use Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory;

final class CustomAuthenticatorTest extends FunctionalTestCase
{
    public function testABadgeAddedByACustomAuthenticatorIsVerifiedOnAnUnlistedFirewall(): void
    {
        $client = self::browser();

        $client->request('GET', '/custom', server: [
            'HTTP_X_USER' => 'alice',
            'HTTP_X_TURNSTILE' => TurnstileMockResponseFactory::token('api'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('body', 'Custom firewall: alice');
    }

    public function testARefusedBadgeFailsTheLoginWithItsErrorCodesAndNoToken(): void
    {
        $client = self::browser();
        $token = TurnstileMockResponseFactory::token('login');

        $client->request('GET', '/custom', server: ['HTTP_X_USER' => 'alice', 'HTTP_X_TURNSTILE' => $token]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, self::userLookups($client));
        $body = (string) $client->getResponse()->getContent();
        self::assertJsonStringEqualsJsonString(json_encode([
            'message' => 'Verification failed. Refresh the page and try again.',
            'message_key' => 'Verification failed. Refresh the page and try again.',
            'error_codes' => ['action-mismatch'],
        ], \JSON_THROW_ON_ERROR), $body);
        self::assertStringNotContainsString($token, $body);
    }
}
