<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Http;

/**
 * A single event the audit service refused in an otherwise accepted (202) batch.
 */
final readonly class RejectedEvent
{
    /**
     * @param int    $index   position in the submitted batch
     * @param string $eventId event ID (resolved from the index when the service omits it)
     */
    public function __construct(
        public int $index,
        public string $eventId,
        public string $reason,
        public bool $retryable,
    ) {
    }
}
