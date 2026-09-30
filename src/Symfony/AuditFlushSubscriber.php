<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Symfony;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Turnkey\AuditClient\AuditLoggerInterface;

/**
 * Flushes buffered non-critical events at the end of the unit of work:
 *
 *  - kernel.terminate  — after the response was sent (Response::send() already called
 *                        fastcgi_finish_request(), so the client does not wait)
 *  - console.terminate / console.error — console commands
 *  - Messenger worker events — after each handled/failed message and when the worker stops
 *
 * Registered with the lowest priority so events logged by other terminate listeners are included.
 * Event names are strings, so symfony/messenger and symfony/console are not required.
 */
final class AuditFlushSubscriber implements EventSubscriberInterface
{
    private const int PRIORITY = -1024;

    public function __construct(private readonly AuditLoggerInterface $auditLogger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.terminate' => ['flush', self::PRIORITY],
            'console.terminate' => ['flush', self::PRIORITY],
            'console.error' => ['flush', self::PRIORITY],
            'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent' => ['flush', self::PRIORITY],
            'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent' => ['flush', self::PRIORITY],
            'Symfony\Component\Messenger\Event\WorkerStoppedEvent' => ['flush', self::PRIORITY],
        ];
    }

    public function flush(): void
    {
        $this->auditLogger->flush();
    }
}
