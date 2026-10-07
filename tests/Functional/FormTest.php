<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional;

use Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory;

final class FormTest extends FunctionalTestCase
{
    public function testRendersTheWidgetPostingUnderTheFieldName(): void
    {
        $client = self::browser();
        $crawler = $client->request('GET', '/reset');

        $widget = $crawler->filter('form[name="reset"] div.cf-turnstile');
        self::assertCount(1, $widget);
        self::assertSame('test-site-key', $widget->attr('data-sitekey'));
        self::assertSame('password_reset', $widget->attr('data-action'));
        self::assertSame('reset[turnstile]', $widget->attr('data-response-field-name'));
        self::assertSame('auto', $widget->attr('data-theme'));
        self::assertSame('flexible', $widget->attr('data-size'));
        self::assertSame('en', $widget->attr('data-language'), 'The language defaults to the request locale.');
        self::assertSame('auto', $widget->attr('data-refresh-expired'));
        self::assertSame('auto', $widget->attr('data-refresh-timeout'));
        self::assertCount(0, $crawler->filter('input[name="reset[turnstile]"]'), 'The widget\'s script adds the input itself.');
        self::assertCount(0, $crawler->filter('label[for="reset_turnstile"]'));
    }

    public function testRendersTheThemeSizeAndLanguageGiven(): void
    {
        $client = self::browser();
        $crawler = $client->request('GET', '/reset?theme=dark&size=compact&language=pl');

        $widget = $crawler->filter('div.cf-turnstile');
        self::assertSame('dark', $widget->attr('data-theme'));
        self::assertSame('compact', $widget->attr('data-size'));
        self::assertSame('pl', $widget->attr('data-language'));
    }

    public function testAcceptsAValidToken(): void
    {
        $client = self::browser();

        $client->request('POST', '/reset', ['reset' => ['email' => 'a@example.com', 'turnstile' => TurnstileMockResponseFactory::token('password_reset')]]);

        self::assertSelectorTextSame('body', 'Sent');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideRefusedSubmissions(): iterable
    {
        yield 'no token' => [['email' => 'a@example.com']];
        yield 'an invalid token' => [['email' => 'a@example.com', 'turnstile' => 'forged']];
        yield 'a token for another action' => [['email' => 'a@example.com', 'turnstile' => TurnstileMockResponseFactory::token('login')]];
    }

    /**
     * @param array<string, string> $submission
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideRefusedSubmissions')]
    public function testRefusesWithOneMessage(array $submission): void
    {
        $client = self::browser();

        $client->request('POST', '/reset', ['reset' => $submission]);

        self::assertSelectorTextContains('form', 'Verification failed. Refresh the page and try again.');
    }

    public function testWithoutValidationTheTokenIsLeftForALaterCheck(): void
    {
        $client = self::browser();

        $client->request('POST', '/reset-late', ['reset' => ['email' => 'a@example.com', 'turnstile' => 'forged']]);
        self::assertJsonStringEqualsJsonString('{"success":false,"error_codes":["invalid-input-response"]}', (string) $client->getResponse()->getContent());

        $client->request('POST', '/reset-late', ['reset' => ['email' => 'a@example.com', 'turnstile' => TurnstileMockResponseFactory::token('password_reset')]]);
        self::assertJsonStringEqualsJsonString('{"success":true,"error_codes":[]}', (string) $client->getResponse()->getContent());

        self::assertSame(2, self::siteverifyRequests($client), 'Each token is verified once, by the later check alone.');
    }
}
