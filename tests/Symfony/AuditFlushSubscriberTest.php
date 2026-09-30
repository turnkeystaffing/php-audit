<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Turnkey\AuditClient\AuditLoggerInterface;
use Turnkey\AuditClient\Event\AuditEvent;
use Turnkey\AuditClient\Symfony\AuditFlushSubscriber;

final class AuditFlushSubscriberTest extends TestCase
{
    public function testFlushesOnKernelTerminate(): void
    {
        [$dispatcher, $logger] = $this->setUpDispatcher();

        $kernel = $this->createStub(HttpKernelInterface::class);
        $dispatcher->dispatch(new TerminateEvent($kernel, new Request(), new Response()), KernelEvents::TERMINATE);

        self::assertSame(1, $logger->flushes);
    }

    public function testFlushesAfterMessengerMessage(): void
    {
        [$dispatcher, $logger] = $this->setUpDispatcher();

        $dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'async'));

        self::assertSame(1, $logger->flushes);
    }

    public function testRunsAfterOtherTerminateListeners(): void
    {
        [$dispatcher] = $this->setUpDispatcher();

        $listeners = $dispatcher->getListeners(KernelEvents::TERMINATE);
        $dispatcher->addListener(KernelEvents::TERMINATE, static function (): void {}, -100);

        self::assertSame(-1024, $dispatcher->getListenerPriority(KernelEvents::TERMINATE, $listeners[0]));
    }

    public function testSubscribesToConsoleEvents(): void
    {
        $events = AuditFlushSubscriber::getSubscribedEvents();

        self::assertArrayHasKey('console.terminate', $events);
        self::assertArrayHasKey('console.error', $events);
        self::assertArrayHasKey('Symfony\Component\Messenger\Event\WorkerStoppedEvent', $events);
    }

    /**
     * @return array{EventDispatcher, object{flushes: int}&AuditLoggerInterface}
     */
    private function setUpDispatcher(): array
    {
        $logger = new class () implements AuditLoggerInterface {
            public int $flushes = 0;

            public function log(AuditEvent $event): void
            {
            }

            public function logCritical(AuditEvent $event): void
            {
            }

            public function flush(): void
            {
                $this->flushes++;
            }
        };

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AuditFlushSubscriber($logger));

        return [$dispatcher, $logger];
    }
}
