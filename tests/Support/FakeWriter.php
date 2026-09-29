<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Support;

use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Exception\WriteFailedException;
use Turnkey\AuditClient\Writer\AuditWriterInterface;

final class FakeWriter implements AuditWriterInterface
{
    /** @var list<list<FinalAuditEvent>> */
    public array $batches = [];

    public bool $fail = false;

    /** @var list<string> event IDs to hand to the next tier */
    public array $forwardIds = [];

    public function __construct(private readonly string $name = 'fake')
    {
    }

    public function writeBatch(array $events): array
    {
        $this->batches[] = $events;
        if ($this->fail) {
            throw new WriteFailedException($this->name, 'simulated failure');
        }

        return array_values(array_filter($events, fn (FinalAuditEvent $e) => in_array($e->id(), $this->forwardIds, true)));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function eventCount(): int
    {
        return array_sum(array_map('count', $this->batches));
    }
}
