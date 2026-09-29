<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Replay;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Turnkey\AuditClient\Config\FileConfig;
use Turnkey\AuditClient\Config\FileReplayConfig;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Replay\FileReplayer;
use Turnkey\AuditClient\Replay\ReplayResult;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\HttpScenario;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;
use Turnkey\AuditClient\Tests\Support\TempDir;
use Turnkey\AuditClient\Writer\FileWriter;

final class FileReplayerTest extends TestCase
{
    private const string NOW = '2026-09-29 12:00:00';

    private TempDir $dir;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->dir = new TempDir();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function testReplaysClosedFileAndDeletesIt(): void
    {
        $events = $this->writeFile('2026-09-28', 3);
        $http = HttpScenario::of(self::healthy(), HttpScenario::acceptAll());

        $result = $this->replayer($http)->replayOnce();

        self::assertSame(ReplayResult::DONE, $result->status);
        self::assertSame(1, $result->filesCompleted);
        self::assertSame(3, $result->eventsDelivered);
        self::assertSame(['.replay.lock'], $this->dir->files());
        self::assertSame(
            array_map(static fn ($e) => $e->id(), $events),
            array_column($http->requests[1]['body']['events'], 'event_id'),
        );
    }

    public function testSkipsCurrentPeriodFile(): void
    {
        $this->writeFile('2026-09-29', 2);
        $http = HttpScenario::of(self::healthy(), HttpScenario::acceptAll());

        $result = $this->replayer($http)->replayOnce();

        self::assertSame(ReplayResult::DONE, $result->status);
        self::assertCount(0, $http->requests);
        self::assertContains('audit-2026-09-29.jsonl', $this->dir->files());
    }

    public function testSkipsRecentlyModifiedFile(): void
    {
        $this->writeFile('2026-09-28', 1);
        touch($this->dir->path . '/audit-2026-09-28.jsonl', (new \DateTimeImmutable(self::NOW))->getTimestamp() - 10);
        $http = HttpScenario::of(self::healthy(), HttpScenario::acceptAll());

        $this->replayer($http)->replayOnce();

        self::assertCount(0, $http->requests);
    }

    public function testUnhealthyServiceSendsNothing(): void
    {
        $this->writeFile('2026-09-28', 1);
        $http = HttpScenario::of(HttpScenario::status(503));

        $result = $this->replayer($http)->replayOnce();

        self::assertSame(ReplayResult::UNHEALTHY, $result->status);
        self::assertSame(0, $http->postCount());
        self::assertContains('audit-2026-09-28.jsonl', $this->dir->files());
    }

    public function testProcessesFilesOldestFirst(): void
    {
        $older = $this->writeFile('2026-09-26', 1);
        $newer = $this->writeFile('2026-09-27', 1);
        $http = HttpScenario::of(self::healthy(), HttpScenario::acceptAll());

        $this->replayer($http)->replayOnce();

        self::assertSame($older[0]->id(), $http->requests[1]['body']['events'][0]['event_id']);
        self::assertSame($newer[0]->id(), $http->requests[2]['body']['events'][0]['event_id']);
    }

    public function testTransientFailureKeepsProgressAndResumesWithoutDuplicates(): void
    {
        $events = $this->writeFile('2026-09-28', 5);
        // health, batch 1 ok, batch 2 fails (503 twice: attempt + retry)
        $http = HttpScenario::of(self::healthy(), HttpScenario::acceptAll(), HttpScenario::status(503));

        $first = $this->replayer($http, batchSize: 2)->replayOnce();

        self::assertSame(ReplayResult::RETRY_LATER, $first->status);
        self::assertSame(2, $first->eventsDelivered);
        self::assertContains('audit-2026-09-28.jsonl.replaying', $this->dir->files());
        self::assertContains('audit-2026-09-28.jsonl.replaying.offset', $this->dir->files());

        $http2 = HttpScenario::of(self::healthy(), HttpScenario::acceptAll());
        $second = $this->replayer($http2, batchSize: 2)->replayOnce();

        self::assertSame(ReplayResult::DONE, $second->status);
        self::assertSame(3, $second->eventsDelivered);
        $resent = array_merge(...array_map(
            static fn (array $r) => array_column($r['body']['events'] ?? [], 'event_id'),
            array_slice($http2->requests, 1),
        ));
        self::assertSame(array_map(static fn ($e) => $e->id(), array_slice($events, 2)), $resent);
        self::assertSame(['.replay.lock'], $this->dir->files());
    }

    public function testPermanentRejectionDropsBatchAndContinues(): void
    {
        $this->writeFile('2026-09-28', 4);
        $http = HttpScenario::of(self::healthy(), HttpScenario::status(400), HttpScenario::acceptAll());

        $result = $this->replayer($http, batchSize: 2)->replayOnce();

        self::assertSame(ReplayResult::DONE, $result->status);
        self::assertSame(2, $result->eventsDropped);
        self::assertSame(2, $result->eventsDelivered);
        self::assertTrue($this->logger->has('error', 'rejected permanently, dropping'));
    }

    public function testPerEventPermanentRejectionIsDropped(): void
    {
        $this->writeFile('2026-09-28', 2);
        $http = HttpScenario::of(self::healthy(), HttpScenario::rejectIndexes([1 => false]));

        $result = $this->replayer($http)->replayOnce();

        self::assertSame(ReplayResult::DONE, $result->status);
        self::assertSame(1, $result->eventsDelivered);
        self::assertSame(1, $result->eventsDropped);
    }

    public function testRetryableRejectionStopsForRetry(): void
    {
        $this->writeFile('2026-09-28', 2);
        $http = HttpScenario::of(self::healthy(), HttpScenario::rejectIndexes([1 => true]));

        $result = $this->replayer($http)->replayOnce();

        self::assertSame(ReplayResult::RETRY_LATER, $result->status);
        self::assertContains('audit-2026-09-28.jsonl.replaying', $this->dir->files());
    }

    public function testMalformedLinesAreSkipped(): void
    {
        $path = $this->dir->path . '/audit-2026-09-28.jsonl';
        file_put_contents($path, "{broken\n\n" . Events::final()->toJson() . "\n");
        touch($path, strtotime('2026-09-28 12:00:00'));
        $http = HttpScenario::of(self::healthy(), HttpScenario::acceptAll());

        $result = $this->replayer($http)->replayOnce();

        self::assertSame(1, $result->eventsDelivered);
        self::assertSame(1, $result->eventsDropped);
        self::assertTrue($this->logger->has('error', 'malformed JSON line'));
    }

    public function testSecondReplayerIsLockedOut(): void
    {
        $this->writeFile('2026-09-28', 1);
        $lock = fopen($this->dir->path . '/.replay.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            $result = $this->replayer(HttpScenario::of(self::healthy()))->replayOnce();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertSame(ReplayResult::LOCKED, $result->status);
    }

    public function testMissingDirectoryIsNothingToDo(): void
    {
        $replayer = new FileReplayer(
            new FileConfig($this->dir->path . '/missing'),
            HttpScenario::of(self::healthy())->auditClient(),
            HttpDeliveryProfile::batch(),
            new FileReplayConfig(),
            $this->logger,
        );

        self::assertSame(ReplayResult::DONE, $replayer->replayOnce()->status);
    }

    public function testReadsGoWrittenFiles(): void
    {
        $path = $this->dir->path . '/audit-2026-09-28.jsonl';
        copy(__DIR__ . '/../Fixtures/go_final_event.json', $path);
        touch($path, strtotime('2026-09-28 12:00:00'));
        $http = HttpScenario::of(self::healthy(), HttpScenario::acceptAll());

        $result = $this->replayer($http)->replayOnce();

        self::assertSame(1, $result->eventsDelivered);
        self::assertSame('0192f3a1-7c2e-7b4a-9d3e-1a2b3c4d5e6f', $http->requests[1]['body']['events'][0]['event_id']);
    }

    /** @return list<FinalAuditEvent> */
    private function writeFile(string $day, int $count): array
    {
        $events = Events::finals($count);
        $writer = new FileWriter(
            new FileConfig($this->dir->path),
            $this->logger,
            static fn () => new \DateTimeImmutable($day . ' 08:00:00', new \DateTimeZone('UTC')),
        );
        $writer->writeBatch($events);
        touch($this->dir->path . "/audit-$day.jsonl", strtotime($day . ' 23:00:00'));

        return $events;
    }

    private function replayer(HttpScenario $http, int $batchSize = 100): FileReplayer
    {
        return new FileReplayer(
            new FileConfig($this->dir->path),
            $http->auditClient(logger: $this->logger),
            new HttpDeliveryProfile(timeoutSeconds: 1.0, maxRetries: 1),
            new FileReplayConfig(batchSize: $batchSize),
            $this->logger,
            static fn () => new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')),
        );
    }

    private static function healthy(): MockResponse
    {
        return new MockResponse('ok', ['http_code' => 200]);
    }
}
