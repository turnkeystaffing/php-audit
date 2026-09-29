<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\Config\RedisQueueConfig;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Exception\WriteFailedException;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\InMemoryRedisClient;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;
use Turnkey\AuditClient\Writer\RedisQueueWriter;

final class RedisQueueWriterTest extends TestCase
{
    private const string KEY = 'audit:fallback:queue';

    public function testPushesJsonEventsInOneLpushAndSetsTtl(): void
    {
        $redis = new InMemoryRedisClient();
        $writer = new RedisQueueWriter($redis, new RedisQueueConfig(), new RecordingLogger());
        $events = Events::finals(3);

        self::assertSame([], $writer->writeBatch($events));

        self::assertSame(1, $redis->lPushCalls);
        self::assertCount(3, $redis->items(self::KEY));
        self::assertSame(7 * 24 * 3600, $redis->ttls[self::KEY]);
        // Oldest event sits at the tail, so RPOP returns it first.
        $tail = $redis->items(self::KEY)[2];
        self::assertSame($events[0]->id(), FinalAuditEvent::fromJson($tail)->id());
    }

    public function testRejectsBatchWhenQueueIsFull(): void
    {
        $redis = new InMemoryRedisClient();
        $redis->lists[self::KEY] = array_fill(0, 9, '{}');
        $writer = new RedisQueueWriter($redis, new RedisQueueConfig(maxQueueSize: 10), new RecordingLogger());

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('queue capacity exceeded');
        $writer->writeBatch(Events::finals(2));
    }

    public function testUnlimitedQueueSkipsLengthCheck(): void
    {
        $redis = new InMemoryRedisClient();
        $redis->failLLen = new \RuntimeException('must not be called');
        $writer = new RedisQueueWriter($redis, new RedisQueueConfig(maxQueueSize: 0), new RecordingLogger());

        $writer->writeBatch(Events::finals(1));

        self::assertCount(1, $redis->items(self::KEY));
    }

    public function testLlenFailureDoesNotBlockDelivery(): void
    {
        $redis = new InMemoryRedisClient();
        $redis->failLLen = new \RuntimeException('timeout');
        $logger = new RecordingLogger();
        $writer = new RedisQueueWriter($redis, new RedisQueueConfig(), $logger);

        $writer->writeBatch(Events::finals(1));

        self::assertCount(1, $redis->items(self::KEY));
        self::assertTrue($logger->has('warning', 'LLEN check failed'));
    }

    public function testLpushFailureThrows(): void
    {
        $redis = new InMemoryRedisClient();
        $redis->failLPush = new \RuntimeException('connection refused');
        $writer = new RedisQueueWriter($redis, new RedisQueueConfig(), new RecordingLogger());

        $this->expectException(WriteFailedException::class);
        $this->expectExceptionMessage('LPUSH failed');
        $writer->writeBatch(Events::finals(1));
    }

    public function testExpireFailureIsOnlyAWarning(): void
    {
        $redis = new InMemoryRedisClient();
        $redis->failExpire = new \RuntimeException('oops');
        $logger = new RecordingLogger();
        $writer = new RedisQueueWriter($redis, new RedisQueueConfig(), $logger);

        self::assertSame([], $writer->writeBatch(Events::finals(1)));
        self::assertTrue($logger->has('warning', 'failed to set queue TTL'));
    }

    public function testNegativeTtlDisablesExpiry(): void
    {
        $redis = new InMemoryRedisClient();
        $logger = new RecordingLogger();
        $writer = new RedisQueueWriter($redis, new RedisQueueConfig(queueTtlSeconds: -1), $logger);

        $writer->writeBatch(Events::finals(1));

        self::assertArrayNotHasKey(self::KEY, $redis->ttls);
        self::assertTrue($logger->has('warning', 'PII may persist'));
    }

    public function testZeroTtlMeansDefault(): void
    {
        self::assertSame(RedisQueueConfig::DEFAULT_TTL_SECONDS, (new RedisQueueConfig(queueTtlSeconds: 0))->queueTtlSeconds);
    }
}
