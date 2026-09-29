<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Config\QueueConsumerConfig;
use Turnkey\AuditClient\Config\RedisQueueConfig;
use Turnkey\AuditClient\Consumer\RedisQueueConsumer;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Redis\PrefixedRedisClient;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\HttpScenario;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;
use Turnkey\AuditClient\Writer\RedisQueueWriter;

/**
 * Runs against a real Redis (make test-integration starts redis:7-alpine).
 */
#[Group('integration')]
final class RedisIntegrationTest extends TestCase
{
    private const string PREFIX = 'php-audit-test:';

    private Client $predis;
    private PrefixedRedisClient $redis;

    protected function setUp(): void
    {
        $url = getenv('REDIS_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('REDIS_URL is not set');
        }

        $this->predis = new Client($url);
        $this->predis->del([self::PREFIX . RedisQueueConfig::DEFAULT_QUEUE_KEY]);
        $this->redis = new PrefixedRedisClient($this->predis, self::PREFIX);
    }

    public function testWriterUsesPrefixedKeyWithTtl(): void
    {
        (new RedisQueueWriter($this->redis, new RedisQueueConfig(), new RecordingLogger()))->writeBatch(Events::finals(3));

        $key = self::PREFIX . RedisQueueConfig::DEFAULT_QUEUE_KEY;
        self::assertSame(3, $this->predis->llen($key));
        self::assertGreaterThan(7 * 24 * 3600 - 10, $this->predis->ttl($key));
    }

    public function testRpopWithCountReturnsOldestFirst(): void
    {
        $events = Events::finals(3);
        $writer = new RedisQueueWriter($this->redis, new RedisQueueConfig(), new RecordingLogger());
        foreach ($events as $event) {
            $writer->writeBatch([$event]);
        }

        $popped = $this->redis->rPop(RedisQueueConfig::DEFAULT_QUEUE_KEY, 2);

        self::assertCount(2, $popped);
        self::assertSame($events[0]->id(), FinalAuditEvent::fromJson($popped[0])->id());
        self::assertSame($events[1]->id(), FinalAuditEvent::fromJson($popped[1])->id());
        self::assertSame([], $this->redis->rPop('missing-key', 5));
    }

    public function testConsumerRequeuesAndLaterDeliversInOrder(): void
    {
        $events = Events::finals(4);
        $writer = new RedisQueueWriter($this->redis, new RedisQueueConfig(), new RecordingLogger());
        foreach ($events as $event) {
            $writer->writeBatch([$event]);
        }

        $down = HttpScenario::of(HttpScenario::status(503));
        $result = $this->consumer($down)->consumeBatch();
        self::assertTrue($result->serviceUnavailable());
        self::assertSame(4, $this->redis->lLen(RedisQueueConfig::DEFAULT_QUEUE_KEY));

        $up = HttpScenario::of(HttpScenario::acceptAll());
        $this->consumer($up)->consumeBatch();

        self::assertSame(
            array_map(static fn ($e) => $e->id(), $events),
            array_column($up->requests[0]['body']['events'], 'event_id'),
        );
        self::assertSame(0, $this->redis->lLen(RedisQueueConfig::DEFAULT_QUEUE_KEY));
    }

    public function testPing(): void
    {
        $this->redis->ping();
        $this->addToAssertionCount(1);
    }

    private function consumer(HttpScenario $http): RedisQueueConsumer
    {
        return new RedisQueueConsumer(
            $this->redis,
            $http->auditClient(),
            new HttpDeliveryProfile(timeoutSeconds: 1.0, maxRetries: 0),
            new RedisQueueConfig(),
            new QueueConsumerConfig(),
            new RecordingLogger(),
        );
    }
}
