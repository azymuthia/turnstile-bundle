<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional\App;

use Azymuthia\TurnstileBundle\AzymuthiaTurnstileBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $turnstileConfig merged over the default azymuthia_turnstile configuration
     */
    public function __construct(
        string $environment = 'test',
        bool $debug = true,
        private readonly array $turnstileConfig = [],
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new AzymuthiaTurnstileBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->varDir().'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->varDir().'/log';
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(SiteverifySpy::class)) {
            return;
        }

        // Only so tests can count the requests sent to "Cloudflare": the factory the README wires, behind a spy.
        $container->getDefinition(AzymuthiaTurnstileBundle::HTTP_CLIENT)->setArgument(0, new Reference(SiteverifySpy::class));
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'form' => true,
            'validation' => true,
            'translator' => ['fallbacks' => ['en']],
            'router' => ['utf8' => true],
            'rate_limiter' => ['enabled' => true],
            'property_info' => ['with_constructor_extractor' => true],
        ]);

        $container->extension('twig', [
            'default_path' => __DIR__.'/templates',
            'strict_variables' => true,
        ]);

        $container->extension('security', [
            'password_hashers' => [PasswordAuthenticatedUserInterface::class => 'plaintext'],
            'providers' => ['spy' => ['id' => SpyUserProvider::class]],
            'firewalls' => [
                'custom' => [
                    'pattern' => '^/custom',
                    'stateless' => true,
                    'provider' => 'spy',
                    'custom_authenticators' => [CustomTurnstileAuthenticator::class],
                ],
                'main' => [
                    'lazy' => true,
                    'provider' => 'spy',
                    'entry_point' => 'form_login',
                    'form_login' => [
                        'login_path' => 'login',
                        'check_path' => 'login',
                        'enable_csrf' => true,
                    ],
                    'http_basic' => null,
                    'login_throttling' => ['max_attempts' => 3],
                ],
            ],
        ]);

        $container->extension('azymuthia_turnstile', array_replace_recursive([
            'site_key' => 'test-site-key',
            'secret_key' => 'test-secret',
            'firewalls' => [
                'main' => ['action' => 'login'],
            ],
        ], $this->turnstileConfig));

        // The wiring the README documents for an application's tests.
        $container->import(__DIR__.'/config/turnstile_test.yaml');

        $container->services()
            ->defaults()->autowire()->autoconfigure()
            ->set('logger', NullLogger::class)
            ->set(SpyUserProvider::class)->public()
            ->set(CustomTurnstileAuthenticator::class)
            ->set(AppController::class)->public()->tag('controller.service_arguments');

        if ('test' === $this->environment) {
            $container->services()->set(SiteverifySpy::class)->autowire()->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('home', '/')->controller([AppController::class, 'home']);
        $routes->add('login', '/login')->controller([AppController::class, 'login']);
        $routes->add('custom', '/custom')->controller([AppController::class, 'custom']);
        $routes->add('reset', '/reset')->controller([AppController::class, 'reset']);
        $routes->add('reset_late', '/reset-late')->controller([AppController::class, 'resetLate']);
    }

    private function varDir(): string
    {
        return sys_get_temp_dir().'/azymuthia-turnstile-bundle/'.md5(serialize($this->turnstileConfig));
    }
}
