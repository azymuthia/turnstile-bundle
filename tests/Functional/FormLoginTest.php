<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional;

use Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class FormLoginTest extends FunctionalTestCase
{
    public function testRendersTheWidgetOnTheLoginPage(): void
    {
        $client = self::browser();
        $crawler = $client->request('GET', '/login');

        $widget = $crawler->filter('form div.cf-turnstile');
        self::assertCount(1, $widget);
        self::assertSame('test-site-key', $widget->attr('data-sitekey'));
        self::assertSame('login', $widget->attr('data-action'));
        self::assertSame('cf-turnstile-response', $widget->attr('data-response-field-name'));
        self::assertCount(1, $crawler->filter('script[src="https://challenges.cloudflare.com/turnstile/v0/api.js"]'));
    }

    public function testLogsInWithAValidToken(): void
    {
        $client = self::browser();

        self::logIn($client, TurnstileMockResponseFactory::token('login'));

        self::assertResponseRedirects('http://localhost/');
        $client->followRedirect();
        self::assertSelectorTextSame('body', 'Logged in as alice');
        self::assertSame(1, self::siteverifyRequests($client));
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function provideRefusedTokens(): iterable
    {
        yield 'no token' => [null, 'missing-input-response'];
        yield 'an invalid token' => ['forged', 'invalid-input-response'];
        yield 'a token for another action' => [TurnstileMockResponseFactory::token('password_reset'), 'action-mismatch'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideRefusedTokens')]
    public function testRefusesBeforeTheUserIsLoadedOrThePasswordChecked(?string $token, string $errorCode): void
    {
        $client = self::browser();

        self::logIn($client, $token);

        self::assertSame(0, self::userLookups($client), 'A refused Turnstile check must not look the user up.');
        self::assertResponseRedirects('http://localhost/login');
        $client->followRedirect();
        self::assertSelectorTextSame('.error', 'Verification failed. Refresh the page and try again.');
        self::assertSelectorTextSame('.error-codes', $errorCode);
    }

    public function testAValidTokenGoesOnToTheUserAndPasswordChecks(): void
    {
        $client = self::browser();

        self::logIn($client, TurnstileMockResponseFactory::token('login'), password: 'wrong');

        self::assertSame(1, self::userLookups($client));
        $client->followRedirect();
        self::assertSelectorTextSame('.error', 'Invalid credentials.');
    }

    public function testCsrfIsStillCheckedFirst(): void
    {
        $client = self::browser();

        self::logIn($client, null, csrfToken: 'forged');
        $client->followRedirect();
        self::assertSelectorTextSame('.error', 'Invalid CSRF token.');

        self::logIn($client, TurnstileMockResponseFactory::token('login'), csrfToken: 'forged');
        $client->followRedirect();
        self::assertSelectorTextSame('.error', 'Invalid CSRF token.');

        self::assertSame(0, self::siteverifyRequests($client), 'A forged CSRF token must be refused before Turnstile is asked.');
        self::assertSame(0, self::userLookups($client));
    }

    public function testTurnstileFailuresCountTowardsLoginThrottling(): void
    {
        $client = self::browser();

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            self::logIn($client, 'forged');
            $client->followRedirect();
            self::assertSelectorTextSame('.error', 'Verification failed. Refresh the page and try again.');
        }

        self::logIn($client, TurnstileMockResponseFactory::token('login'));
        $client->followRedirect();

        self::assertSelectorTextContains('.error', 'Too many failed login attempts');
        self::assertSame(0, self::userLookups($client));
    }

    public function testOtherAuthenticatorsOnTheFirewallAreLeftAlone(): void
    {
        $client = self::browser();

        $client->request('GET', '/', server: ['PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'secret']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('body', 'Logged in as alice');
        self::assertSame(0, self::siteverifyRequests($client));
    }

    private static function logIn(KernelBrowser $client, ?string $token, string $password = 'secret', ?string $csrfToken = null): void
    {
        $crawler = $client->request('GET', '/login');
        $values = [
            '_username' => 'alice',
            '_password' => $password,
            '_csrf_token' => $csrfToken ?? $crawler->filter('input[name="_csrf_token"]')->attr('value'),
        ];

        if (null !== $token) {
            // What the widget's script adds to the form once the visitor passes the challenge.
            $values['cf-turnstile-response'] = $token;
        }

        $client->request('POST', '/login', $values);
    }
}
