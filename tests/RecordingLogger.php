<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\Tests;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string>
     */
    public function levels(): array
    {
        return array_map(static fn (array $record): string => (string) $record['level'], $this->records);
    }

    public function dump(): string
    {
        return json_encode($this->records, \JSON_THROW_ON_ERROR);
    }
}
