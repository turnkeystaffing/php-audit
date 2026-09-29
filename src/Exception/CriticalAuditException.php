<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Exception;

/**
 * A critical (compliance) audit event was not delivered to the audit service.
 *
 * Critical events are delivered synchronously over HTTP only — there is no
 * Redis/File fallback. The caller must reject the originating operation.
 */
final class CriticalAuditException extends AuditException
{
    private function __construct(
        string $message,
        public readonly string $eventId,
        public readonly string $action,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notDelivered(string $eventId, string $action, DeliveryException $cause): self
    {
        return new self(
            sprintf('critical audit event "%s" (%s) not delivered: %s', $action, $eventId, $cause->getMessage()),
            $eventId,
            $action,
            $cause->httpStatus,
            $cause,
        );
    }

    public static function rejected(string $eventId, string $action, string $reason): self
    {
        return new self(
            sprintf('critical audit event "%s" (%s) rejected by audit service: %s', $action, $eventId, $reason),
            $eventId,
            $action,
        );
    }
}
