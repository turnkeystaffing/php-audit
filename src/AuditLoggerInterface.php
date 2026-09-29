<?php

declare(strict_types=1);

namespace Turnkey\AuditClient;

use Turnkey\AuditClient\Event\AuditEvent;
use Turnkey\AuditClient\Exception\CriticalAuditException;
use Turnkey\AuditClient\Exception\InvalidAuditEventException;

/**
 * The interface application code depends on.
 */
interface AuditLoggerInterface
{
    /**
     * Non-critical events are buffered and delivered after the response (HTTP -> Redis -> File);
     * delivery failures are logged, never thrown.
     *
     * Critical events (EventBuilder::withCritical()) are delivered synchronously over HTTP only.
     *
     * @throws InvalidAuditEventException
     * @throws CriticalAuditException when a critical event was not accepted by the audit service
     */
    public function log(AuditEvent $event): void;

    /**
     * Delivers the event synchronously over HTTP only, regardless of its critical flag.
     *
     * @throws InvalidAuditEventException
     * @throws CriticalAuditException
     */
    public function logCritical(AuditEvent $event): void;

    /**
     * Delivers buffered non-critical events. Never throws.
     */
    public function flush(): void;
}
