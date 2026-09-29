<?php

declare(strict_types=1);

namespace Turnkey\AuditClient;

use Symfony\Contracts\Service\ResetInterface;
use Turnkey\AuditClient\Event\AuditEvent;

/**
 * Discards all events. Used when auditing is disabled (AuditConfig::$enabled = false).
 */
final class NoopAuditLogger implements AuditLoggerInterface, ResetInterface
{
    public function log(AuditEvent $event): void
    {
    }

    public function logCritical(AuditEvent $event): void
    {
    }

    public function flush(): void
    {
    }

    public function reset(): void
    {
    }
}
