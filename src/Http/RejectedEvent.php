<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Http;

/**
 * A single event the audit service refused: an entry of errors[] in a 202 batch response,
 * or in a 400 response of the single-event endpoint.
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
