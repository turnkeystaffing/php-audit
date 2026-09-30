<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\Config\FileConfig;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Exception\WriteFailedException;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;
use Turnkey\AuditClient\Tests\Support\TempDir;
use Turnkey\AuditClient\Writer\FileWriter;

final class FileWriterTest extends TestCase
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

    public function testAppendsJsonLinesToDailyFile(): void
    {
        $writer = $this->writer('2026-09-29 10:15:00');
        $first = Events::finals(2);
        $second = Events::finals(1);

        $writer->writeBatch($first);
        $writer->writeBatch($second);

        $path = $this->dir->path . '/audit-2026-09-29.jsonl';
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertCount(3, $lines);
        self::assertSame($first[0]->id(), FinalAuditEvent::fromJson($lines[0])->id());
        self::assertSame($second[0]->id(), FinalAuditEvent::fromJson($lines[2])->id());
    }

    public function testHourlyRotationUsesHourSuffix(): void
    {
        $writer = new FileWriter(
            new FileConfig($this->dir->path, FileConfig::ROTATION_HOURLY),
            new RecordingLogger(),
            static fn () => new \DateTimeImmutable('2026-09-29 07:59:00', new \DateTimeZone('UTC')),
        );

        $writer->writeBatch(Events::finals(1));

        self::assertFileExists($this->dir->path . '/audit-2026-09-29-07.jsonl');
    }

    public function testCreatesDirectoryAndRestrictsPermissions(): void
    {
        $nested = $this->dir->path . '/nested/audit';
        $writer = new FileWriter(new FileConfig($nested), new RecordingLogger(), static fn () => new \DateTimeImmutable('2026-09-29'));

        $writer->writeBatch(Events::finals(1));

        self::assertSame('0700', substr(sprintf('%o', fileperms($nested)), -4));
        self::assertSame('0600', substr(sprintf('%o', fileperms($nested . '/audit-2026-09-29.jsonl')), -4));
    }

    public function testWriteFailureThrowsInsteadOfSilentlyLosingEvents(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            // root ignores permissions; use a path under a regular file instead.
            file_put_contents($this->dir->path . '/not-a-dir', 'x');
            $writer = new FileWriter(new FileConfig($this->dir->path . '/not-a-dir/sub'), new RecordingLogger());
        } else {
            chmod($this->dir->path, 0500);
            $writer = new FileWriter(new FileConfig($this->dir->path), new RecordingLogger());
        }

        try {
            $this->expectException(WriteFailedException::class);
            $writer->writeBatch(Events::finals(1));
        } finally {
            chmod($this->dir->path, 0700);
        }
    }

    public function testWritesToFreshFileWhenReplayerRenamedItMeanwhile(): void
    {
        $writer = $this->writer('2026-09-29 10:00:00');
        $path = $this->dir->path . '/audit-2026-09-29.jsonl';

        $writer->writeBatch(Events::finals(1));
        rename($path, $path . '.replaying');
        $writer->writeBatch(Events::finals(1));

        self::assertCount(1, file($path . '.replaying'));
        self::assertCount(1, file($path));
    }

    public function testRotationValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileConfig($this->dir->path, 'size');
    }

    private function writer(string $now): FileWriter
    {
        return new FileWriter(
            new FileConfig($this->dir->path),
            new RecordingLogger(),
            static fn () => new \DateTimeImmutable($now, new \DateTimeZone('UTC')),
        );
    }
}
