<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional;

use Azymuthia\TurnstileBundle\Tests\Functional\App\SiteverifySpy;
use Azymuthia\TurnstileBundle\Tests\Functional\App\SpyUserProvider;
use Azymuthia\TurnstileBundle\Tests\Functional\App\TestKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class FunctionalTestCase extends WebTestCase
{
    /**
     * @param array{environment?: string, debug?: bool, turnstile?: array<string, mixed>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel($options['environment'] ?? 'test', $options['debug'] ?? true, $options['turnstile'] ?? []);
    }

    protected static function browser(): KernelBrowser
    {
        $client = static::createClient();
        // Keep one container across requests, so the spies below see every request.
        $client->disableReboot();

        // Login throttling keeps its counts in a filesystem pool, which outlives a test.
        $pool = $client->getContainer()->get('cache.rate_limiter');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();

        return $client;
    }

    protected static function userLookups(KernelBrowser $client): int
    {
        $provider = $client->getContainer()->get(SpyUserProvider::class);
        \assert($provider instanceof SpyUserProvider);

        return $provider->lookups;
    }

    protected static function siteverifyRequests(KernelBrowser $client): int
    {
        $spy = $client->getContainer()->get(SiteverifySpy::class);
        \assert($spy instanceof SiteverifySpy);

        return $spy->requests;
    }
}
