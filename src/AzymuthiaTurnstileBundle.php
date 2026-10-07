<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle;

use Azymuthia\TurnstileBundle\Form\TurnstileType;
use Azymuthia\TurnstileBundle\Security\CheckTurnstileBadgeListener;
use Azymuthia\TurnstileBundle\Security\FormLoginTurnstileListener;
use Azymuthia\TurnstileBundle\Twig\TurnstileExtension;
use Azymuthia\TurnstileBundle\Twig\WidgetOptions;
use Azymuthia\TurnstileBundle\Validator\TurnstileValidator;
use Azymuthia\TurnstileBundle\Verifier\TurnstileVerifier;
use Azymuthia\TurnstileBundle\VisitorIp\ClientIpResolver;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class AzymuthiaTurnstileBundle extends AbstractBundle
{
    public const string HTTP_CLIENT = 'azymuthia_turnstile.http_client';

    private const string CLIENT_IP_RESOLVER = 'azymuthia_turnstile.client_ip_resolver';

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $root */
        $root = $definition->rootNode();

        $root
            ->children()
                ->scalarNode('site_key')
                    ->info('The widget\'s site key, e.g. %env(CLOUDFLARE_TURNSTILE_SITE_KEY)%.')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('secret_key')
                    ->info('The widget\'s secret key, e.g. %env(CLOUDFLARE_TURNSTILE_SECRET_KEY)%. Cloudflare\'s dummy secrets are refused in prod.')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->variableNode('allowed_hostnames')
                    ->info('Hostnames a token may be issued on besides the request host: a list, or e.g. %env(csv:CLOUDFLARE_TURNSTILE_ALLOWED_HOSTNAMES)%.')
                    ->defaultValue([])
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => \is_array($value)
                            ? !array_is_list($value) || [] !== array_filter($value, static fn (mixed $hostname): bool => !\is_string($hostname))
                            : !\is_string($value))
                        ->thenInvalid('allowed_hostnames must be a list of hostnames or an env placeholder, %s given.')
                    ->end()
                ->end()
                ->scalarNode('visitor_ip_resolver')
                    ->info('Id of a service implementing VisitorIpResolver; by default Request::getClientIp().')
                    ->defaultNull()
                ->end()
                ->arrayNode('firewalls')
                    ->info('Firewalls whose form_login requires a Turnstile token.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('action')
                                ->info('The widget\'s data-action the token must be issued for.')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('field')
                                ->info('The posted form field holding the token.')
                                ->defaultValue(WidgetOptions::DEFAULT_FIELD)
                                ->cannotBeEmpty()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('framework', [
            'http_client' => [
                'scoped_clients' => [
                    self::HTTP_CLIENT => ['base_uri' => 'https://challenges.cloudflare.com'],
                ],
            ],
        ]);
        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', [
                'form_themes' => ['@AzymuthiaTurnstile/form/turnstile_widget.html.twig'],
            ]);
        }
    }

    /**
     * @param array{
     *     site_key: string,
     *     secret_key: string,
     *     allowed_hostnames: list<string>|string,
     *     visitor_ip_resolver: string|null,
     *     firewalls: array<string, array{action: string, field: string}>,
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(self::CLIENT_IP_RESOLVER, ClientIpResolver::class);

        $services->set(TurnstileVerifier::class)
            ->args([
                service(self::HTTP_CLIENT),
                service($config['visitor_ip_resolver'] ?? self::CLIENT_IP_RESOLVER),
                service('logger'),
                $config['secret_key'],
                param('kernel.environment'),
                $config['allowed_hostnames'],
            ])
            ->tag('monolog.logger', ['channel' => 'turnstile'])
            ->public();
        $services->alias('azymuthia_turnstile.verifier', TurnstileVerifier::class)->public();

        $services->set(CheckTurnstileBadgeListener::class)
            ->args([service(TurnstileVerifier::class), service('request_stack')])
            ->tag('kernel.event_listener', [
                'event' => CheckPassportEvent::class,
                'priority' => CheckTurnstileBadgeListener::PRIORITY,
            ]);

        foreach ($config['firewalls'] as $firewall => $firewallConfig) {
            $services->set('azymuthia_turnstile.form_login_listener.'.$firewall, FormLoginTurnstileListener::class)
                ->args([service('request_stack'), $firewallConfig['action'], $firewallConfig['field']])
                ->tag('kernel.event_listener', [
                    'event' => CheckPassportEvent::class,
                    'priority' => FormLoginTurnstileListener::PRIORITY,
                    'dispatcher' => 'security.event_dispatcher.'.$firewall,
                ]);
        }

        $services->set(WidgetOptions::class)
            ->args([$config['site_key'], service('request_stack')]);

        $services->set(TurnstileType::class)
            ->args([service(WidgetOptions::class)])
            ->tag('form.type');

        $services->set(TurnstileValidator::class)
            ->args([service(TurnstileVerifier::class), service('request_stack')])
            ->tag('validator.constraint_validator');

        $services->set(TurnstileExtension::class)
            ->args([service(WidgetOptions::class)])
            ->tag('twig.extension');
    }
}
