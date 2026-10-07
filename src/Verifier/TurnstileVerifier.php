<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Verifier;

use Azymuthia\TurnstileBundle\VisitorIp\VisitorIpResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies a Turnstile token with Cloudflare's siteverify and refuses whenever it cannot verify.
 *
 * Besides `success`, the token must have been issued on the request host (or an allowed hostname)
 * for the expected action. Cloudflare's dummy test secrets answer with hostname `localhost` and
 * action `test`, so for those alone the match is skipped, and in `prod` they refuse every check.
 */
final readonly class TurnstileVerifier
{
    public const string SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** @see https://developers.cloudflare.com/turnstile/troubleshooting/testing/ */
    public const array DUMMY_SECRET_KEYS = [
        '1x0000000000000000000000000000000AA',
        '2x0000000000000000000000000000000AA',
        '3x0000000000000000000000000000000AA',
    ];

    private const int MAX_TOKEN_LENGTH = 2048;

    /**
     * @param list<string> $allowedHostnames hostnames accepted besides the request host
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private VisitorIpResolver $visitorIpResolver,
        private LoggerInterface $logger,
        private string $secretKey,
        private string $environment,
        private array $allowedHostnames = [],
    ) {
    }

    public function verify(?string $token, string $action, Request $request): VerificationResult
    {
        if ('' === trim($this->secretKey)) {
            $this->logger->critical('Turnstile secret key is not configured; refusing every check.');

            return VerificationResult::failure([ErrorCode::INVALID_INPUT_SECRET]);
        }

        $isDummySecret = \in_array($this->secretKey, self::DUMMY_SECRET_KEYS, true);

        if ($isDummySecret && 'prod' === $this->environment) {
            $this->logger->critical('Turnstile is configured with a Cloudflare dummy secret key in prod; refusing every check.');

            return VerificationResult::failure([ErrorCode::INVALID_INPUT_SECRET]);
        }

        if (null === $token || '' === $token) {
            return $this->reject([ErrorCode::MISSING_INPUT_RESPONSE]);
        }

        if (self::MAX_TOKEN_LENGTH < \strlen($token)) {
            return $this->reject([ErrorCode::INVALID_INPUT_RESPONSE]);
        }

        $data = $this->siteverify($token, $this->visitorIpResolver->resolve($request));

        if (null === $data) {
            return VerificationResult::failure([ErrorCode::UNREACHABLE]);
        }

        if (true !== $data['success']) {
            return $this->reject($this->sanitizeErrorCodes($data['error-codes'] ?? null));
        }

        if ($isDummySecret) {
            return VerificationResult::success();
        }

        $hostname = $data['hostname'] ?? null;

        if (!\is_string($hostname) || !$this->isAllowedHostname($hostname, $request->getHost())) {
            return $this->reject([ErrorCode::HOSTNAME_MISMATCH]);
        }

        $issuedAction = $data['action'] ?? null;

        if (!\is_string($issuedAction) || !hash_equals($action, $issuedAction)) {
            return $this->reject([ErrorCode::ACTION_MISMATCH]);
        }

        return VerificationResult::success();
    }

    /**
     * @return array{success: bool, ...<array-key, mixed>}|null null when siteverify could not give an answer
     */
    private function siteverify(string $token, ?string $remoteIp): ?array
    {
        $body = [
            'secret' => $this->secretKey,
            'response' => $token,
            'idempotency_key' => Uuid::v4()->toRfc4122(),
        ];

        if (null !== $remoteIp && '' !== $remoteIp) {
            $body['remoteip'] = $remoteIp;
        }

        try {
            $response = $this->httpClient->request('POST', self::SITEVERIFY_URL, [
                'body' => $body,
                'timeout' => 5.0,
                'max_duration' => 10.0,
            ]);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Turnstile siteverify answered HTTP {status_code}.', [
                    'status_code' => $statusCode,
                    'error_codes' => [ErrorCode::UNREACHABLE],
                ]);

                return null;
            }

            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            $this->logger->error('Turnstile siteverify could not be reached.', [
                'exception_class' => $exception::class,
                'error_codes' => [ErrorCode::UNREACHABLE],
            ]);

            return null;
        }

        if (!\is_bool($data['success'] ?? null)) {
            $this->logger->error('Turnstile siteverify answered without a boolean success.', [
                'error_codes' => [ErrorCode::UNREACHABLE],
            ]);

            return null;
        }

        return $data;
    }

    private function isAllowedHostname(string $hostname, string $requestHost): bool
    {
        foreach ([$requestHost, ...$this->allowedHostnames] as $allowedHostname) {
            if (hash_equals(strtolower($allowedHostname), strtolower($hostname))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function sanitizeErrorCodes(mixed $errorCodes): array
    {
        if (!\is_array($errorCodes)) {
            return [];
        }

        return array_values(array_filter(
            $errorCodes,
            static fn (mixed $errorCode): bool => \is_string($errorCode)
                && 1 === preg_match('/^[a-z0-9-]{1,100}$/D', $errorCode),
        ));
    }

    /**
     * @param list<string> $errorCodes
     */
    private function reject(array $errorCodes): VerificationResult
    {
        $this->logger->warning('Turnstile verification refused.', [
            'error_codes' => $errorCodes,
        ]);

        return VerificationResult::failure($errorCodes);
    }
}
