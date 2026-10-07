<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests\Functional\App;

use Azymuthia\TurnstileBundle\Test\TurnstileMockResponseFactory;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Counts the requests the mock response factory answers.
 */
final class SiteverifySpy
{
    public int $requests = 0;

    public function __construct(
        private readonly TurnstileMockResponseFactory $factory,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        ++$this->requests;

        return ($this->factory)($method, $url, $options);
    }
}
