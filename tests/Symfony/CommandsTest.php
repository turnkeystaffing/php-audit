<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use Turnkey\AuditClient\Config\FileConfig;
use Turnkey\AuditClient\Config\FileReplayConfig;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Config\QueueConsumerConfig;
use Turnkey\AuditClient\Config\RedisQueueConfig;
use Turnkey\AuditClient\Consumer\RedisQueueConsumer;
use Turnkey\AuditClient\Replay\FileReplayer;
use Turnkey\AuditClient\Symfony\Command\ConsumeQueueCommand;
use Turnkey\AuditClient\Symfony\Command\ReplayFilesCommand;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\HttpScenario;
use Turnkey\AuditClient\Tests\Support\InMemoryRedisClient;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;
use Turnkey\AuditClient\Tests\Support\TempDir;
use Turnkey\AuditClient\Writer\FileWriter;
use Turnkey\AuditClient\Writer\RedisQueueWriter;

final class CommandsTest extends TestCase
{
    // --- audit:consume-queue ---

    public function testConsumeDrainsQueueAndExits(): void
    {
        $redis = new InMemoryRedisClient();
        (new RedisQueueWriter($redis, new RedisQueueConfig(), new RecordingLogger()))->writeBatch(Events::finals(5));
        $http = HttpScenario::of(HttpScenario::acceptAll());

        $tester = new CommandTester(new ConsumeQueueCommand($this->consumer($redis, $http, batchSize: 2)));
        $exit = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(3, $http->postCount());
        self::assertSame([], $redis->items(RedisQueueConfig::DEFAULT_QUEUE_KEY));
        self::assertStringContainsString('delivered 5', $tester->getDisplay());
    }

    public function testConsumeStopsWhenServiceUnavailable(): void
    {
        $redis = new InMemoryRedisClient();
        (new RedisQueueWriter($redis, new RedisQueueConfig(), new RecordingLogger()))->writeBatch(Events::finals(3));
        $http = HttpScenario::of(HttpScenario::status(503));

        $tester = new CommandTester(new ConsumeQueueCommand($this->consumer($redis, $http, batchSize: 1)));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertCount(3, $redis->items(RedisQueueConfig::DEFAULT_QUEUE_KEY));
        self::assertStringContainsString('audit service unavailable', $tester->getDisplay());
    }

    public function testConsumeRespectsMaxBatches(): void
    {
        $redis = new InMemoryRedisClient();
        (new RedisQueueWriter($redis, new RedisQueueConfig(), new RecordingLogger()))->writeBatch(Events::finals(5));
        $http = HttpScenario::of(HttpScenario::acceptAll());

        (new CommandTester(new ConsumeQueueCommand($this->consumer($redis, $http, batchSize: 1))))->execute(['--max-batches' => '2']);

        self::assertSame(2, $http->postCount());
        self::assertCount(3, $redis->items(RedisQueueConfig::DEFAULT_QUEUE_KEY));
    }

    public function testConsumeLoopStopsAtTimeLimit(): void
    {
        $redis = new InMemoryRedisClient();
        $http = HttpScenario::of(HttpScenario::acceptAll());

        $started = microtime(true);
        $exit = (new CommandTester(new ConsumeQueueCommand($this->consumer($redis, $http))))
            ->execute(['--loop' => true, '--time-limit' => '1', '--interval' => '1']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertLessThan(3.0, microtime(true) - $started);
    }

    public function testConsumeFailsWhenRedisIsDown(): void
    {
        $redis = new InMemoryRedisClient();
        $redis->failRPop = new \RuntimeException('connection refused');

        $tester = new CommandTester(new ConsumeQueueCommand($this->consumer($redis, HttpScenario::of(HttpScenario::acceptAll()))));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('connection refused', $tester->getDisplay());
    }

    // --- audit:replay ---

    public function testReplayDeliversFiles(): void
    {
        $dir = new TempDir();
        try {
            $writer = new FileWriter(new FileConfig($dir->path), new RecordingLogger(), static fn () => new \DateTimeImmutable('2020-01-01'));
            $writer->writeBatch(Events::finals(2));
            $http = HttpScenario::of(new MockResponse('ok', ['http_code' => 200]), HttpScenario::acceptAll());

            $tester = new CommandTester(new ReplayFilesCommand($this->replayer($dir, $http)));
            $exit = $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertStringContainsString('done, files 1, delivered 2', $tester->getDisplay());
            self::assertSame(['.replay.lock'], $dir->files());
        } finally {
            $dir->remove();
        }
    }

    public function testReplayReportsAlreadyRunning(): void
    {
        $dir = new TempDir();
        $lock = fopen($dir->path . '/.replay.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $tester = new CommandTester(new ReplayFilesCommand($this->replayer($dir, HttpScenario::of(HttpScenario::acceptAll()))));

            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('already running', $tester->getDisplay());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            $dir->remove();
        }
    }

    public function testCommandsSubscribeToTermination(): void
    {
        $command = new ConsumeQueueCommand($this->consumer(new InMemoryRedisClient(), HttpScenario::of(HttpScenario::acceptAll())));

        self::assertSame('audit:consume-queue', $command->getName());
        if (\defined('SIGTERM')) {
            self::assertContains(\SIGTERM, $command->getSubscribedSignals());
        }
        self::assertFalse($command->handleSignal(15));
    }

    private function consumer(InMemoryRedisClient $redis, HttpScenario $http, int $batchSize = 100): RedisQueueConsumer
    {
        return new RedisQueueConsumer(
            $redis,
            $http->auditClient(),
            HttpDeliveryProfile::batch(),
            new RedisQueueConfig(),
            new QueueConsumerConfig($batchSize),
            new RecordingLogger(),
        );
    }

    private function replayer(TempDir $dir, HttpScenario $http): FileReplayer
    {
        return new FileReplayer(
            new FileConfig($dir->path),
            $http->auditClient(),
            HttpDeliveryProfile::batch(),
            new FileReplayConfig(minFileAgeSeconds: 0),
            new RecordingLogger(),
        );
    }
}
