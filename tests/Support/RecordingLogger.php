<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Support;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    public function has(string $level, string $messageFragment): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $messageFragment)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<mixed>|null */
    public function contextOf(string $messageFragment): ?array
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $messageFragment)) {
                return $record['context'];
            }
        }

        return null;
    }
}
