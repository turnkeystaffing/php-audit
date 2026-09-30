<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\AuditClientFactory;
use Turnkey\AuditClient\AuditRouter;
use Turnkey\AuditClient\Config\AuditClientConfig;
use Turnkey\AuditClient\NoopAuditLogger;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\FixedTokenProvider;
use Turnkey\AuditClient\Tests\Support\HttpScenario;
use Turnkey\AuditClient\Tests\Support\InMemoryRedisClient;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;
use Turnkey\AuditClient\Tests\Support\TempDir;

final class AuditClientFactoryTest extends TestCase
{
    private TempDir $dir;

    protected function setUp(): void
    {
        $this->dir = new TempDir();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testConfigFromArrayAppliesDefaults(): void
    {
        $config = AuditClientConfig::fromArray([
            'base_url' => 'https://audit.example.com',
            'service_name' => 'getnative',
            'file_directory' => $this->dir->path,
        ]);

        self::assertTrue($config->audit->enabled);
        self::assertSame(100, $config->audit->batchSize);
        self::assertEquals(2.0, $config->batchProfile->timeoutSeconds);
        self::assertSame(1, $config->batchProfile->maxRetries);
        self::assertEquals(3.0, $config->criticalProfile->timeoutSeconds);
        self::assertSame(2, $config->criticalProfile->maxRetries);
        self::assertSame('audit:fallback:queue', $config->redisQueue->queueKey);
        self::assertSame(10000, $config->redisQueue->maxQueueSize);
        self::assertSame('daily', $config->file->rotation);
    }

    public function testConfigFromArrayOverrides(): void
    {
        $config = AuditClientConfig::fromArray([
            'base_url' => 'https://audit.example.com',
            'service_name' => 'getnative',
            'file_directory' => $this->dir->path,
            'enabled' => 'false',
            'batch_size' => '50',
            'critical_timeout' => '5',
            'file_rotation' => 'hourly',
        ]);

        self::assertFalse($config->audit->enabled);
        self::assertSame(50, $config->audit->batchSize);
        self::assertEquals(5.0, $config->criticalProfile->timeoutSeconds);
        self::assertSame('hourly', $config->file->rotation);
    }

    public function testDisabledAuditReturnsNoop(): void
    {
        $factory = $this->factory(['enabled' => false]);

        self::assertInstanceOf(NoopAuditLogger::class, $factory->createLogger());
    }

    public function testFullChainFallsBackToRedisThenFile(): void
    {
        $redis = new InMemoryRedisClient();
        $http = HttpScenario::of(HttpScenario::status(500));
        $factory = $this->factory([], $http, $redis);
        $logger = $factory->createLogger();
        self::assertInstanceOf(AuditRouter::class, $logger);

        $logger->log(Events::event());
        $logger->flush();
        self::assertCount(1, $redis->items('audit:fallback:queue'));

        $redis->failLPush = new \RuntimeException('down');
        $logger->log(Events::event());
        $logger->flush();
        self::assertCount(1, glob($this->dir->path . '/audit-*.jsonl') ?: []);
    }

    public function testWithoutRedisFallsBackStraightToFile(): void
    {
        $http = HttpScenario::of(HttpScenario::status(500));
        $logger = $this->factory([], $http)->createLogger();

        $logger->log(Events::event());
        $logger->flush();

        self::assertCount(1, glob($this->dir->path . '/audit-*.jsonl') ?: []);
    }

    public function testQueueConsumerRequiresRedis(): void
    {
        $this->expectException(\LogicException::class);
        $this->factory()->createQueueConsumer();
    }

    public function testCreatesConsumerAndReplayer(): void
    {
        $factory = $this->factory([], null, new InMemoryRedisClient());

        self::assertTrue($factory->createQueueConsumer()->consumeBatch()->isEmpty());
        $factory->createFileReplayer()->replayOnce();
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function factory(array $overrides = [], ?HttpScenario $http = null, ?InMemoryRedisClient $redis = null): AuditClientFactory
    {
        $http ??= HttpScenario::of(HttpScenario::acceptAll());

        return new AuditClientFactory(
            AuditClientConfig::fromArray($overrides + [
                'base_url' => HttpScenario::BASE_URL,
                'service_name' => 'getnative',
                'file_directory' => $this->dir->path,
                'http_max_retries' => 0,
            ]),
            new FixedTokenProvider(),
            $http->client,
            new RecordingLogger(),
            $redis,
        );
    }
}
