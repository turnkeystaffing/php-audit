<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Replay;

final readonly class ReplayResult
{
    /** Every eligible file was delivered (or there was nothing to do). */
    public const string DONE = 'done';
    /** Another replayer holds the lock. */
    public const string LOCKED = 'locked';
    /** The audit service health check failed; nothing was sent. */
    public const string UNHEALTHY = 'unhealthy';
    /** A transient delivery error stopped the pass; progress is saved and will resume. */
    public const string RETRY_LATER = 'retry_later';

    public function __construct(
        public string $status,
        public int $filesCompleted = 0,
        public int $eventsDelivered = 0,
        public int $eventsDropped = 0,
    ) {
    }

    public function shouldBackOff(): bool
    {
        return $this->status === self::UNHEALTHY || $this->status === self::RETRY_LATER;
    }
}
