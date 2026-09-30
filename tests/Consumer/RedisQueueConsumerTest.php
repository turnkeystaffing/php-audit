<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Consumer;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Config\QueueConsumerConfig;
use Turnkey\AuditClient\Config\RedisQueueConfig;
use Turnkey\AuditClient\Consumer\ConsumeResult;
use Turnkey\AuditClient\Consumer\RedisQueueConsumer;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\HttpScenario;
use Turnkey\AuditClient\Tests\Support\InMemoryRedisClient;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;
use Turnkey\AuditClient\Writer\RedisQueueWriter;

final class RedisQueueConsumerTest extends TestCase
{
    private const string KEY = 'audit:fallback:queue';

    private InMemoryRedisClient $redis;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedisClient();
        $this->logger = new RecordingLogger();
    }

    public function testEmptyQueue(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());

        $result = $this->consumer($http)->consumeBatch();

        self::assertTrue($result->isEmpty());
        self::assertCount(0, $http->requests);
    }

    public function testDeliversOldestEventsFirstInBatches(): void
    {
        $events = $this->enqueue(5);
        $http = HttpScenario::of(HttpScenario::acceptAll());
        $consumer = $this->consumer($http, batchSize: 3);

        $first = $consumer->consumeBatch();
        $second = $consumer->consumeBatch();
        $third = $consumer->consumeBatch();

        self::assertSame(3, $first->delivered);
        self::assertSame(2, $second->delivered);
        self::assertTrue($third->isEmpty());
        $sentIds = array_merge(...array_map(
            static fn (array $r) => array_column($r['body']['events'], 'event_id'),
            $http->requests,
        ));
        self::assertSame(array_map(static fn ($e) => $e->id(), $events), $sentIds);
    }

    public function testServiceUnavailableReturnsBatchToQueueInOriginalOrder(): void
    {
        $events = $this->enqueue(3);
        $before = $this->redis->items(self::KEY);
        $http = HttpScenario::of(HttpScenario::status(503));

        $result = $this->consumer($http)->consumeBatch();

        self::assertTrue($result->serviceUnavailable());
        self::assertSame(3, $result->requeued);
        self::assertSame($before, $this->redis->items(self::KEY));
        self::assertSame(7 * 24 * 3600, $this->redis->ttls[self::KEY]);

        // Next run delivers them oldest first.
        $http2 = HttpScenario::of(HttpScenario::acceptAll());
        $this->consumer($http2)->consumeBatch();
        self::assertSame(
            array_map(static fn ($e) => $e->id(), $events),
            array_column($http2->requests[0]['body']['events'], 'event_id'),
        );
    }

    public function testRequeuedEventsStayBehindNewerOnes(): void
    {
        $this->enqueue(2);
        $this->consumer(HttpScenario::of(HttpScenario::networkError()))->consumeBatch();
        $newer = $this->enqueue(1);

        $items = $this->redis->items(self::KEY);

        self::assertSame($newer[0]->id(), FinalAuditEvent::fromJson($items[0])->id(), 'newest at head');
    }

    public function testClientErrorDropsBatchWithLog(): void
    {
        $this->enqueue(2);
        $http = HttpScenario::of(HttpScenario::status(400));

        $result = $this->consumer($http)->consumeBatch();

        self::assertSame(2, $result->dropped);
        self::assertSame([], $this->redis->items(self::KEY));
        self::assertTrue($this->logger->has('error', 'rejected permanently, dropping'));
    }

    public function testPartialRejectionRequeuesRetryableAndDropsPermanent(): void
    {
        $events = $this->enqueue(3);
        $http = HttpScenario::of(HttpScenario::rejectIndexes([0 => true, 1 => false]));

        $result = $this->consumer($http)->consumeBatch();

        self::assertSame(1, $result->delivered);
        self::assertSame(1, $result->requeued);
        self::assertSame(1, $result->dropped);
        $remaining = $this->redis->items(self::KEY);
        self::assertCount(1, $remaining);
        self::assertSame($events[0]->id(), FinalAuditEvent::fromJson($remaining[0])->id());
    }

    public function testMalformedAndOversizedItemsAreDropped(): void
    {
        $this->redis->lists[self::KEY] = [str_repeat('x', 6000), '{broken', Events::final()->toJson()];
        $http = HttpScenario::of(HttpScenario::acceptAll());

        $result = $this->consumer($http, maxEventBytes: 5000)->consumeBatch();

        self::assertSame(1, $result->delivered);
        self::assertSame(2, $result->dropped);
        self::assertTrue($this->logger->has('error', 'malformed event'));
        self::assertTrue($this->logger->has('error', 'exceeds size limit'));
    }

    public function testRedisFailureOnPopPropagates(): void
    {
        $this->redis->failRPop = new \RuntimeException('connection refused');

        $this->expectException(\RuntimeException::class);
        $this->consumer(HttpScenario::of(HttpScenario::acceptAll()))->consumeBatch();
    }

    public function testRequeueFailureIsLogged(): void
    {
        $this->enqueue(1);
        $this->redis->failRPush = new \RuntimeException('down');

        $result = $this->consumer(HttpScenario::of(HttpScenario::status(500)))->consumeBatch();

        self::assertSame(ConsumeResult::REQUEUED, $result->status);
        self::assertTrue($this->logger->has('error', 'failed to return events to queue'));
    }

    /** @return list<FinalAuditEvent> oldest first */
    private function enqueue(int $count): array
    {
        $events = Events::finals($count);
        $writer = new RedisQueueWriter($this->redis, new RedisQueueConfig(), $this->logger);
        foreach ($events as $event) {
            $writer->writeBatch([$event]);
        }

        return $events;
    }

    private function consumer(HttpScenario $http, int $batchSize = 100, int $maxEventBytes = 1_048_576): RedisQueueConsumer
    {
        return new RedisQueueConsumer(
            $this->redis,
            $http->auditClient(logger: $this->logger),
            HttpDeliveryProfile::batch(),
            new RedisQueueConfig(),
            new QueueConsumerConfig($batchSize, $maxEventBytes),
            $this->logger,
        );
    }
}
