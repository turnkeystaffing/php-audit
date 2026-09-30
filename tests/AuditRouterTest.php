<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\AuditRouter;
use Turnkey\AuditClient\Config\AuditConfig;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Enricher\EnricherInterface;
use Turnkey\AuditClient\Event\AuditEvent;
use Turnkey\AuditClient\Event\EventBuilder;
use Turnkey\AuditClient\Exception\CriticalAuditException;
use Turnkey\AuditClient\Exception\InvalidAuditEventException;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\FakeWriter;
use Turnkey\AuditClient\Tests\Support\FixedTokenProvider;
use Turnkey\AuditClient\Tests\Support\HttpScenario;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;

final class AuditRouterTest extends TestCase
{
    private RecordingLogger $logger;
    private FakeWriter $first;
    private FakeWriter $second;
    private FakeWriter $third;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->first = new FakeWriter('http');
        $this->second = new FakeWriter('redis-queue');
        $this->third = new FakeWriter('file');
    }

    // --- non-critical: buffering ---

    public function testNonCriticalEventsAreBufferedUntilFlush(): void
    {
        $router = $this->router();

        $router->log(Events::event());
        $router->log(Events::event());

        self::assertSame(2, $router->bufferedCount());
        self::assertSame([], $this->first->batches);

        $router->flush();

        self::assertSame(0, $router->bufferedCount());
        self::assertCount(1, $this->first->batches);
        self::assertCount(2, $this->first->batches[0]);
    }

    public function testFlushesImmediatelyWhenBatchSizeReached(): void
    {
        $router = $this->router(batchSize: 3);

        $router->log(Events::event());
        $router->log(Events::event());
        self::assertSame([], $this->first->batches);

        $router->log(Events::event());

        self::assertCount(1, $this->first->batches);
        self::assertSame(0, $router->bufferedCount());
    }

    public function testFlushWithEmptyBufferDoesNothing(): void
    {
        $router = $this->router();

        $router->flush();

        self::assertSame([], $this->first->batches);
    }

    public function testSecondFlushDoesNotResend(): void
    {
        $router = $this->router();
        $router->log(Events::event());

        $router->flush();
        $router->flush();

        self::assertSame(1, $this->first->eventCount());
    }

    public function testResetFlushes(): void
    {
        $router = $this->router();
        $router->log(Events::event());

        $router->reset();

        self::assertSame(1, $this->first->eventCount());
    }

    public function testInvalidEventIsRejected(): void
    {
        $router = $this->router();

        $this->expectException(InvalidAuditEventException::class);
        $router->log((new EventBuilder())->withSuccess()->build());
    }

    // --- non-critical: fallback chain ---

    public function testFallsBackToNextWriterOnFailure(): void
    {
        $this->first->fail = true;
        $router = $this->router();
        $router->log(Events::event());

        $router->flush();

        self::assertSame(1, $this->first->eventCount());
        self::assertSame(1, $this->second->eventCount());
        self::assertSame([], $this->third->batches);
        self::assertTrue($this->logger->has('warning', 'writer failed, trying next'));
    }

    public function testFallsBackToLastResort(): void
    {
        $this->first->fail = true;
        $this->second->fail = true;
        $router = $this->router();
        $router->log(Events::event());

        $router->flush();

        self::assertSame(1, $this->third->eventCount());
    }

    public function testLogsLossWhenAllWritersFail(): void
    {
        $this->first->fail = true;
        $this->second->fail = true;
        $this->third->fail = true;
        $router = $this->router();
        $router->log(Events::event());

        $router->flush();

        self::assertTrue($this->logger->has('error', 'all writers failed, events lost'));
    }

    public function testOnlyForwardedEventsReachNextWriter(): void
    {
        $router = $this->router();
        $a = Events::event('a');
        $b = Events::event('b');
        $this->first->forwardIds = [$b->id->toRfc4122()];
        $router->log($a);
        $router->log($b);

        $router->flush();

        self::assertSame(2, $this->first->eventCount());
        self::assertSame(1, $this->second->eventCount());
        self::assertSame($b->id->toRfc4122(), $this->second->batches[0][0]->id());
        self::assertSame([], $this->third->batches);
    }

    public function testFlushNeverThrows(): void
    {
        $router = new AuditRouter(
            new AuditConfig(),
            HttpScenario::of(HttpScenario::acceptAll())->auditClient(),
            HttpDeliveryProfile::critical(),
            [new class () extends \stdClass implements \Turnkey\AuditClient\Writer\AuditWriterInterface {
                public function writeBatch(array $events): array
                {
                    throw new \Error('boom');
                }

                public function name(): string
                {
                    return 'broken';
                }
            }],
            $this->logger,
            registerShutdownFunction: false,
        );
        $router->log(Events::event());

        $router->flush();

        self::assertTrue($this->logger->has('error', 'events lost'));
    }

    public function testChunksLargeBuffersByBatchSize(): void
    {
        $router = $this->router(batchSize: 2);
        for ($i = 0; $i < 5; $i++) {
            $router->log(Events::event());
        }
        $router->flush();

        self::assertSame([2, 2, 1], array_map('count', $this->first->batches));
    }

    // --- critical ---

    public function testCriticalEventIsSentSynchronouslyBypassingBuffer(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());
        $router = $this->router(http: $http);

        $router->log(Events::event('password_change', critical: true));

        self::assertSame(1, $http->postCount());
        self::assertSame(0, $router->bufferedCount());
        self::assertSame([], $this->first->batches);
        self::assertEquals(3.0, $http->requests[0]['options']['timeout']);
    }

    public function testCriticalFailureThrowsAndNeverFallsBack(): void
    {
        $http = HttpScenario::of(HttpScenario::status(503));
        $router = $this->router(http: $http);
        $event = Events::event('password_change', critical: true);

        try {
            $router->log($event);
            self::fail('expected CriticalAuditException');
        } catch (CriticalAuditException $e) {
            self::assertSame($event->id->toRfc4122(), $e->eventId);
            self::assertSame('password_change', $e->action);
            self::assertSame(503, $e->httpStatus);
        }

        self::assertSame(3, $http->postCount()); // 1 attempt + 2 retries
        self::assertSame([], $this->first->batches);
        self::assertSame([], $this->second->batches);
        self::assertSame([], $this->third->batches);
        self::assertSame(0, $router->bufferedCount());
    }

    public function testCriticalRejectedByServiceThrows(): void
    {
        $http = HttpScenario::of(HttpScenario::rejectIndexes([0 => false], 'schema violation'));
        $router = $this->router(http: $http);

        $this->expectException(CriticalAuditException::class);
        $this->expectExceptionMessage('schema violation');
        $router->log(Events::event('password_change', critical: true));
    }

    public function testCriticalMissingFromAcceptedIdsThrows(): void
    {
        $http = HttpScenario::of(new \Symfony\Component\HttpClient\Response\MockResponse(
            '{"accepted":0,"rejected":0,"event_ids":[],"errors":[]}',
            ['http_code' => 202],
        ));
        $router = $this->router(http: $http);

        $this->expectException(CriticalAuditException::class);
        $this->expectExceptionMessage('missing from accepted');
        $router->log(Events::event('password_change', critical: true));
    }

    public function testCriticalTokenFailureThrows(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());
        $router = $this->router(http: $http, tokens: FixedTokenProvider::failing(new \RuntimeException('no token')));

        $this->expectException(CriticalAuditException::class);
        $router->log(Events::event('password_change', critical: true));
    }

    public function testCriticalClientErrorIsNotRetried(): void
    {
        $http = HttpScenario::of(HttpScenario::status(422));
        $router = $this->router(http: $http);

        try {
            $router->log(Events::event('password_change', critical: true));
            self::fail('expected CriticalAuditException');
        } catch (CriticalAuditException) {
        }

        self::assertSame(1, $http->postCount());
    }

    public function testLogCriticalForcesSynchronousDelivery(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());
        $router = $this->router(http: $http);

        $router->logCritical(Events::event('password_change'));

        self::assertSame(1, $http->postCount());
        self::assertSame(0, $router->bufferedCount());
    }

    public function testCriticalDoesNotFlushBufferedEvents(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());
        $router = $this->router(http: $http);
        $router->log(Events::event('routine'));

        $router->log(Events::event('password_change', critical: true));

        self::assertSame(1, $router->bufferedCount());
        self::assertCount(1, $http->requests[0]['body']['events']);
    }

    // --- enrichment ---

    public function testEnrichersRunImmediatelyAndSplitEnrichmentKeys(): void
    {
        $calls = 0;
        $enricher = new class ($calls) implements EnricherInterface {
            public function __construct(private int &$calls)
            {
            }

            public function enrich(AuditEvent $event): void
            {
                $this->calls++;
                $event->addContext('enrichment.client_id', 'web');
                $event->addContext('tenant', 'acme');
                $event->addContext('email', 'overwrite-attempt');
            }
        };
        $router = $this->router(enrichers: [$enricher]);
        $event = (new EventBuilder())->withAction('x')->withSuccess()->withContextValue('email', 'a@b.c')->build();

        $router->log($event);
        self::assertSame(1, $calls); // before flush

        $router->flush();
        $final = $this->first->batches[0][0];

        self::assertSame(['email' => 'a@b.c', 'tenant' => 'acme'], $final->event->contextData);
        self::assertSame(['enrichment.client_id' => 'web'], $final->enrichmentMetadata);
        self::assertSame(['email' => 'a@b.c'], $event->contextData, 'caller event is not mutated');
    }

    public function testFailingEnricherDoesNotStopEvent(): void
    {
        $enricher = new class () implements EnricherInterface {
            public function enrich(AuditEvent $event): void
            {
                throw new \RuntimeException('lookup failed');
            }
        };
        $router = $this->router(enrichers: [$enricher]);

        $router->log(Events::event());
        $router->flush();

        self::assertSame(1, $this->first->eventCount());
        self::assertTrue($this->logger->has('warning', 'enricher failed'));
    }

    public function testSlowEnrichersAreReported(): void
    {
        $enricher = new class () implements EnricherInterface {
            public function enrich(AuditEvent $event): void
            {
                usleep(5000);
            }
        };
        $router = new AuditRouter(
            new AuditConfig(enricherBudgetMs: 1),
            HttpScenario::of(HttpScenario::acceptAll())->auditClient(),
            HttpDeliveryProfile::critical(),
            [$this->first],
            $this->logger,
            [$enricher],
            registerShutdownFunction: false,
        );

        $router->log(Events::event());

        self::assertTrue($this->logger->has('warning', 'exceeded time budget'));
    }

    public function testRequiresAtLeastOneWriter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuditRouter(new AuditConfig(), HttpScenario::of(HttpScenario::acceptAll())->auditClient(), HttpDeliveryProfile::critical(), [], $this->logger);
    }

    /**
     * @param list<EnricherInterface> $enrichers
     */
    private function router(int $batchSize = 100, ?HttpScenario $http = null, array $enrichers = [], ?FixedTokenProvider $tokens = null): AuditRouter
    {
        $http ??= HttpScenario::of(HttpScenario::acceptAll());

        return new AuditRouter(
            new AuditConfig(batchSize: $batchSize),
            $http->auditClient($tokens, $this->logger),
            HttpDeliveryProfile::critical(),
            [$this->first, $this->second, $this->third],
            $this->logger,
            $enrichers,
            registerShutdownFunction: false,
        );
    }
}
