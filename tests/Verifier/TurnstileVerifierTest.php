<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Verifier;

use Azymuthia\TurnstileBundle\Tests\RecordingLogger;
use Azymuthia\TurnstileBundle\Verifier\ErrorCode;
use Azymuthia\TurnstileBundle\Verifier\TurnstileVerifier;
use Azymuthia\TurnstileBundle\VisitorIp\ClientIpResolver;
use Azymuthia\TurnstileBundle\VisitorIp\VisitorIpResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class TurnstileVerifierTest extends TestCase
{
    private const string SECRET = 'production-style-secret';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testVerifiesTokenForTheRequestHostAndAction(): void
    {
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $url);
                self::assertSame(5.0, $options['timeout']);
                self::assertSame(10.0, $options['max_duration']);

                parse_str($options['body'], $body);

                self::assertSame(self::SECRET, $body['secret']);
                self::assertSame('valid-token', $body['response']);
                self::assertSame('203.0.113.10', $body['remoteip']);
                self::assertIsString($body['idempotency_key']);
                self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $body['idempotency_key']);

                return self::siteverify(['success' => true, 'hostname' => 'auth.example.com', 'action' => 'login']);
            },
        );

        $result = $this->verifier($httpClient)->verify('valid-token', 'login', self::request('AUTH.example.com', '203.0.113.10'));

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->getErrorCodes());
    }

    public function testSendsTheAddressFromTheVisitorIpResolver(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            parse_str($options['body'], $body);
            self::assertSame('198.51.100.7', $body['remoteip']);

            return self::siteverify(['success' => true, 'hostname' => 'auth.example.com', 'action' => 'login']);
        });
        $resolver = new class implements VisitorIpResolver {
            public function resolve(Request $request): ?string
            {
                return $request->headers->get('CF-Connecting-IP');
            }
        };

        $request = self::request('auth.example.com', '172.64.0.1');
        $request->headers->set('CF-Connecting-IP', '198.51.100.7');

        self::assertTrue($this->verifier($httpClient, resolver: $resolver)->verify('token', 'login', $request)->isSuccess());
    }

    public function testOmitsRemoteIpWhenThereIsNone(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            parse_str($options['body'], $body);
            self::assertArrayNotHasKey('remoteip', $body);

            return self::siteverify(['success' => true, 'hostname' => 'auth.example.com', 'action' => 'login']);
        });

        self::assertTrue($this->verifier($httpClient)->verify('token', 'login', self::request(remoteIp: null))->isSuccess());
    }

    /**
     * @param array<string, mixed> $response
     * @param list<string>         $errorCodes
     */
    #[DataProvider('provideRefusals')]
    public function testRefusesProviderFailureAndIdentityMismatches(array $response, array $errorCodes): void
    {
        $result = $this->verifier(new MockHttpClient(self::siteverify($response)))->verify('token', 'login', self::request());

        self::assertFalse($result->isSuccess());
        self::assertSame($errorCodes, $result->getErrorCodes());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'provider rejection' => [['success' => false, 'error-codes' => ['timeout-or-duplicate']], ['timeout-or-duplicate']];
        yield 'provider codes are sanitized' => [['success' => false, 'error-codes' => ['bad-request', 'Not A Code <script>', 42, str_repeat('a', 101)]], ['bad-request']];
        yield 'missing hostname' => [['success' => true, 'action' => 'login'], [ErrorCode::HOSTNAME_MISMATCH]];
        yield 'wrong hostname' => [['success' => true, 'hostname' => 'attacker.example', 'action' => 'login'], [ErrorCode::HOSTNAME_MISMATCH]];
        yield 'missing action' => [['success' => true, 'hostname' => 'auth.example.com'], [ErrorCode::ACTION_MISMATCH]];
        yield 'wrong action' => [['success' => true, 'hostname' => 'auth.example.com', 'action' => 'password_reset'], [ErrorCode::ACTION_MISMATCH]];
        yield 'action differing in case' => [['success' => true, 'hostname' => 'auth.example.com', 'action' => 'Login'], [ErrorCode::ACTION_MISMATCH]];
    }

    public function testRefusesADummyStyleAnswerForAProductionSecret(): void
    {
        $httpClient = new MockHttpClient(self::siteverify(['success' => true, 'hostname' => 'localhost', 'action' => 'test']));

        $result = $this->verifier($httpClient)->verify('token', 'login', self::request());

        self::assertFalse($result->isSuccess());
        self::assertSame([ErrorCode::HOSTNAME_MISMATCH], $result->getErrorCodes());
    }

    public function testAcceptsAnAllowedHostnameBesidesTheRequestHost(): void
    {
        $response = static fn (): MockResponse => self::siteverify(['success' => true, 'hostname' => 'auth-dev.example.net', 'action' => 'login']);

        self::assertTrue($this->verifier(new MockHttpClient($response), allowedHostnames: ['AUTH-DEV.example.net'])
            ->verify('token', 'login', self::request())->isSuccess());
        self::assertFalse($this->verifier(new MockHttpClient($response), allowedHostnames: ['other.example.net'])
            ->verify('token', 'login', self::request())->isSuccess());
    }

    public function testRefusesAMissingOrOverlongTokenWithoutCallingCloudflare(): void
    {
        $httpClient = new MockHttpClient(static fn (): never => throw new \LogicException('Unexpected request.'));
        $verifier = $this->verifier($httpClient);

        self::assertSame([ErrorCode::MISSING_INPUT_RESPONSE], $verifier->verify(null, 'login', self::request())->getErrorCodes());
        self::assertSame([ErrorCode::MISSING_INPUT_RESPONSE], $verifier->verify('', 'login', self::request())->getErrorCodes());
        self::assertSame([ErrorCode::INVALID_INPUT_RESPONSE], $verifier->verify(str_repeat('x', 2049), 'login', self::request())->getErrorCodes());
        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testAcceptsATokenOfTheMaximumLength(): void
    {
        $httpClient = new MockHttpClient(self::siteverify(['success' => true, 'hostname' => 'auth.example.com', 'action' => 'login']));

        self::assertTrue($this->verifier($httpClient)->verify(str_repeat('x', 2048), 'login', self::request())->isSuccess());
    }

    public function testRefusesEveryCheckWithoutASecret(): void
    {
        $httpClient = new MockHttpClient(static fn (): never => throw new \LogicException('Unexpected request.'));

        $result = $this->verifier($httpClient, secret: ' ')->verify('token', 'login', self::request());

        self::assertFalse($result->isSuccess());
        self::assertSame([ErrorCode::INVALID_INPUT_SECRET], $result->getErrorCodes());
        self::assertSame(['critical'], $this->logger->levels());
    }

    #[DataProvider('provideUnreachableAnswers')]
    public function testRefusesAsUnreachableWhenCloudflareGivesNoAnswer(MockResponse $response): void
    {
        $result = $this->verifier(new MockHttpClient($response))->verify('token', 'login', self::request());

        self::assertFalse($result->isSuccess());
        self::assertSame([ErrorCode::UNREACHABLE], $result->getErrorCodes());
        self::assertSame(['error'], $this->logger->levels());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function provideUnreachableAnswers(): iterable
    {
        yield 'HTTP 503' => [new MockResponse('{}', ['http_code' => 503])];
        yield 'HTTP 400 with a valid-looking body' => [new MockResponse('{"success":true,"hostname":"auth.example.com","action":"login"}', ['http_code' => 400])];
        yield 'invalid JSON' => [new MockResponse('not-json')];
        yield 'JSON that is not an object' => [new MockResponse('"success"')];
        yield 'success that is not a boolean' => [new MockResponse('{"success":1}')];
        yield 'no success at all' => [new MockResponse('{"hostname":"auth.example.com"}')];
        yield 'transport error' => [new MockResponse((static function (): iterable {
            throw new TransportException('Connection failed.');
            // Never reached: it only makes the closure a generator, as MockResponse requires.
            yield ''; // @phpstan-ignore deadCode.unreachable
        })())];
        yield 'timeout' => [new MockResponse([''], ['error' => 'Idle timeout reached for "https://challenges.cloudflare.com/turnstile/v0/siteverify".'])];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDummySecrets(): iterable
    {
        foreach (TurnstileVerifier::DUMMY_SECRET_KEYS as $secret) {
            yield $secret => [$secret];
        }
    }

    #[DataProvider('provideDummySecrets')]
    public function testADummySecretSkipsTheHostnameAndActionMatchOutsideProd(string $secret): void
    {
        foreach (['dev', 'test'] as $environment) {
            $httpClient = new MockHttpClient(self::siteverify(['success' => true, 'hostname' => 'localhost', 'action' => 'test']));

            self::assertTrue(
                $this->verifier($httpClient, secret: $secret, environment: $environment)->verify('token', 'login', self::request())->isSuccess(),
                $environment,
            );
        }
    }

    public function testADummySecretStillHonoursCloudflaresRefusal(): void
    {
        $httpClient = new MockHttpClient(self::siteverify(['success' => false, 'error-codes' => ['invalid-input-response']]));

        $result = $this->verifier($httpClient, secret: TurnstileVerifier::DUMMY_SECRET_KEYS[1], environment: 'dev')->verify('token', 'login', self::request());

        self::assertSame(['invalid-input-response'], $result->getErrorCodes());
    }

    #[DataProvider('provideDummySecrets')]
    public function testADummySecretRefusesEveryCheckInProd(string $secret): void
    {
        $httpClient = new MockHttpClient(static fn (): never => throw new \LogicException('Unexpected request.'));

        $result = $this->verifier($httpClient, secret: $secret, environment: 'prod')->verify('token', 'login', self::request());

        self::assertFalse($result->isSuccess());
        self::assertSame([ErrorCode::INVALID_INPUT_SECRET], $result->getErrorCodes());
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertSame(['critical'], $this->logger->levels());
    }

    public function testNeverLogsTheTokenOrTheSecret(): void
    {
        $token = 'token-that-must-not-leak';
        $answers = [
            self::siteverify(['success' => false, 'error-codes' => ['invalid-input-response']]),
            self::siteverify(['success' => true, 'hostname' => 'attacker.example', 'action' => 'login']),
            self::siteverify(['success' => true, 'hostname' => 'auth.example.com', 'action' => 'other']),
            new MockResponse('{}', ['http_code' => 500]),
            new MockResponse('not-json'),
            new MockResponse([''], ['error' => \sprintf('Failed for %s with %s', $token, self::SECRET)]),
        ];

        foreach ($answers as $answer) {
            $this->verifier(new MockHttpClient($answer))->verify($token, 'login', self::request());
        }
        $this->verifier(new MockHttpClient(), environment: 'prod', secret: TurnstileVerifier::DUMMY_SECRET_KEYS[0])->verify($token, 'login', self::request());

        self::assertCount(\count($answers) + 1, $this->logger->records);
        self::assertStringNotContainsString($token, $this->logger->dump());
        self::assertStringNotContainsString(self::SECRET, $this->logger->dump());
        self::assertStringNotContainsString(TurnstileVerifier::DUMMY_SECRET_KEYS[0], $this->logger->dump());
    }

    /**
     * @param list<string> $allowedHostnames
     */
    private function verifier(
        MockHttpClient $httpClient,
        string $secret = self::SECRET,
        string $environment = 'prod',
        array $allowedHostnames = [],
        VisitorIpResolver $resolver = new ClientIpResolver(),
    ): TurnstileVerifier {
        return new TurnstileVerifier($httpClient, $resolver, $this->logger, $secret, $environment, $allowedHostnames);
    }

    private static function request(string $host = 'auth.example.com', ?string $remoteIp = '203.0.113.10'): Request
    {
        return Request::create('https://'.$host.'/login', 'POST', server: ['REMOTE_ADDR' => $remoteIp ?? '']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function siteverify(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR));
    }
}
