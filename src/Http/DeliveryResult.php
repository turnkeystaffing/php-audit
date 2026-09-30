<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Http;

/**
 * Outcome of a batch the audit service answered with 202 Accepted.
 */
final readonly class DeliveryResult
{
    /**
     * @param list<string>|null  $acceptedIds event_ids from the response; null when the response omitted them
     * @param list<RejectedEvent> $rejected
     */
    public function __construct(
        public ?array $acceptedIds,
        public array $rejected,
    ) {
    }

    /**
     * An event counts as delivered when it is not listed as rejected and, if the
     * service reported accepted IDs, it is among them.
     */
    public function isAccepted(string $eventId): bool
    {
        if ($this->rejectionFor($eventId) !== null) {
            return false;
        }

        return $this->acceptedIds === null || in_array($eventId, $this->acceptedIds, true);
    }

    public function rejectionFor(string $eventId): ?RejectedEvent
    {
        foreach ($this->rejected as $rejected) {
            if ($rejected->eventId === $eventId) {
                return $rejected;
            }
        }

        return null;
    }

    public function hasRejections(): bool
    {
        return $this->rejected !== [];
    }
}
