<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Test;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for Cloudflare's siteverify in tests, through a MockHttpClient replacing
 * `azymuthia_turnstile.http_client`, so tests never call Cloudflare and Turnstile stays on.
 *
 * A token from {@see self::token()} passes for that action, on the configured hostname; anything
 * else is refused with `invalid-input-response`. Use it with a secret that is not one of
 * Cloudflare's dummy secrets, or the hostname and action are not checked at all.
 */
final readonly class TurnstileMockResponseFactory
{
    private const string TOKEN_PREFIX = 'azymuthia-turnstile-test-token.';

    public function __construct(
        private string $hostname = 'localhost',
    ) {
    }

    public static function token(string $action): string
    {
        return self::TOKEN_PREFIX.$action;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $body = [];

        if (\is_string($options['body'] ?? null)) {
            parse_str($options['body'], $body);
        }

        $token = $body['response'] ?? null;

        if (!\is_string($token) || !str_starts_with($token, self::TOKEN_PREFIX) || self::TOKEN_PREFIX === $token) {
            return self::json([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]);
        }

        return self::json([
            'success' => true,
            'challenge_ts' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'hostname' => $this->hostname,
            'action' => substr($token, \strlen(self::TOKEN_PREFIX)),
            'cdata' => '',
            'error-codes' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
