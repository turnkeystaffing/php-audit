<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Consumer;

final readonly class ConsumeResult
{
    public const string EMPTY = 'empty';
    public const string DELIVERED = 'delivered';
    /** The audit service is unavailable; events were returned to the queue. */
    public const string REQUEUED = 'requeued';

    public function __construct(
        public string $status,
        public int $popped = 0,
        public int $delivered = 0,
        public int $requeued = 0,
        public int $dropped = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->status === self::EMPTY;
    }

    public function serviceUnavailable(): bool
    {
        return $this->status === self::REQUEUED;
    }
}
