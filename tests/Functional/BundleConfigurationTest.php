<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional;

use Azymuthia\TurnstileBundle\AzymuthiaTurnstileBundle;
use Azymuthia\TurnstileBundle\Security\TurnstileAuthenticationException;
use Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory;
use Azymuthia\TurnstileBundle\Verifier\TurnstileVerifier;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BundleConfigurationTest extends FunctionalTestCase
{
    public function testHasNoSwitchToTurnTurnstileOff(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "enabled"');

        self::process(['site_key' => 'site', 'secret_key' => 'secret', 'enabled' => false]);
    }

    public function testDefaults(): void
    {
        self::assertSame([
            'site_key' => 'site',
            'secret_key' => 'secret',
            'firewalls' => ['main' => ['action' => 'login', 'field' => 'cf-turnstile-response']],
            'allowed_hostnames' => [],
            'visitor_ip_resolver' => null,
        ], self::process(['site_key' => 'site', 'secret_key' => 'secret', 'firewalls' => ['main' => ['action' => 'login']]]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideIncompleteConfigurations(): iterable
    {
        yield 'no secret key' => [['site_key' => 'site']];
        yield 'an empty secret key' => [['site_key' => 'site', 'secret_key' => '']];
        yield 'no site key' => [['secret_key' => 'secret']];
        yield 'a firewall without an action' => [['site_key' => 'site', 'secret_key' => 'secret', 'firewalls' => ['main' => []]]];
        yield 'allowed hostnames that are not a list' => [['site_key' => 'site', 'secret_key' => 'secret', 'allowed_hostnames' => ['a' => 'auth.example.com']]];
        yield 'allowed hostnames that are not strings' => [['site_key' => 'site', 'secret_key' => 'secret', 'allowed_hostnames' => [['auth.example.com']]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideIncompleteConfigurations')]
    public function testRefusesAnIncompleteConfiguration(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::process($config);
    }

    public function testAnUnknownFirewallFailsTheContainerBuild(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage('security.event_dispatcher.mian');

        self::bootKernel(['turnstile' => ['firewalls' => ['mian' => ['action' => 'login']]]]);
    }

    public function testAllowedHostnamesCanComeFromACsvEnvVar(): void
    {
        $_SERVER['TEST_TURNSTILE_ALLOWED_HOSTNAMES'] = 'auth.example.com,auth-dev.example.net';

        try {
            self::bootKernel(['turnstile' => ['allowed_hostnames' => '%env(csv:TEST_TURNSTILE_ALLOWED_HOSTNAMES)%']]);
            $verifier = self::getContainer()->get(TurnstileVerifier::class);
            \assert($verifier instanceof TurnstileVerifier);

            // The mock answers with hostname "localhost"; here it comes from a request on another host.
            $request = Request::create('https://auth-dev.example.net/');
            self::assertFalse($verifier->verify(TurnstileMockResponseFactory::token('login'), 'login', $request)->isSuccess());

            self::ensureKernelShutdown();
            $_SERVER['TEST_TURNSTILE_ALLOWED_HOSTNAMES'] = 'localhost';
            self::bootKernel(['turnstile' => ['allowed_hostnames' => '%env(csv:TEST_TURNSTILE_ALLOWED_HOSTNAMES)%']]);
            $verifier = self::getContainer()->get(TurnstileVerifier::class);
            \assert($verifier instanceof TurnstileVerifier);

            self::assertTrue($verifier->verify(TurnstileMockResponseFactory::token('login'), 'login', $request)->isSuccess());
        } finally {
            unset($_SERVER['TEST_TURNSTILE_ALLOWED_HOSTNAMES']);
        }
    }

    public function testADummySecretRefusesEveryCheckInAProdKernel(): void
    {
        self::bootKernel(['environment' => 'prod', 'debug' => false, 'turnstile' => ['secret_key' => TurnstileVerifier::DUMMY_SECRET_KEYS[0]]]);
        $verifier = self::getContainer()->get('azymuthia_turnstile.verifier');
        \assert($verifier instanceof TurnstileVerifier);

        $result = $verifier->verify('any-token', 'login', Request::create('https://localhost/'));

        self::assertFalse($result->isSuccess());
        self::assertSame(['invalid-input-secret'], $result->getErrorCodes());
    }

    public function testShipsTheMessageInEveryLocale(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorInterface);

        $messages = [
            'en' => 'Verification failed. Refresh the page and try again.',
            'pl' => 'Weryfikacja nie powiodła się. Odśwież stronę i spróbuj ponownie.',
            'de' => 'Die Überprüfung ist fehlgeschlagen. Lade die Seite neu und versuche es erneut.',
            'es' => 'La verificación ha fallado. Recarga la página e inténtalo de nuevo.',
            'fr' => 'La vérification a échoué. Actualise la page et réessaie.',
        ];

        foreach (['security', 'validators'] as $domain) {
            foreach ($messages as $locale => $message) {
                self::assertSame($message, $translator->trans(TurnstileAuthenticationException::MESSAGE_KEY, [], $domain, $locale));
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function process(array $config): array
    {
        $extension = (new AzymuthiaTurnstileBundle())->getContainerExtension();
        \assert($extension instanceof ConfigurationExtensionInterface);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        \assert($configuration instanceof ConfigurationInterface);

        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
