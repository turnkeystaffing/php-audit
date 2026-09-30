<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Http;

use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Support\Time;

/**
 * Converts events to the audit service ingestion wire format
 * (internal/audit/gateway/dto/ingestion.go:EventDTO on the service side).
 *
 * Like the Go client, ip_address, user_agent, request_id and enrichment
 * metadata are not part of the wire format.
 *
 * @internal
 */
final class EventDtoMapper
{
    private function __construct()
    {
    }

    /**
     * @param list<FinalAuditEvent> $events
     *
     * @return array{events: list<array<string, mixed>>}
     */
    public static function batchRequest(array $events, string $serviceName): array
    {
        return ['events' => array_map(static fn (FinalAuditEvent $e) => self::toDto($e, $serviceName), $events)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toDto(FinalAuditEvent $final, string $serviceName): array
    {
        $e = $final->event;

        $resource = ['type' => $e->resourceType];
        if ($e->resourceId !== null) {
            $resource['id'] = $e->resourceId->toRfc4122();
        }

        $dto = [
            'event_id' => $e->id->toRfc4122(),
            'service' => $serviceName,
            'action' => $e->action,
            'actor' => $e->userId?->toRfc4122() ?? '',
            'resource' => $resource,
            'outcome' => $e->outcome !== null ? $e->outcome->value : '',
            'timestamp' => Time::format($e->timestamp),
        ];

        // omitempty, as in Go
        if ($e->contextData !== []) {
            $dto['metadata'] = (object) $e->contextData;
        }
        if ($e->failureReason !== '') {
            $dto['failure_reason'] = $e->failureReason;
        }

        return $dto;
    }
}
