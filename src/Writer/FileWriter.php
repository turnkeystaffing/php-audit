<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Writer;

use Psr\Log\LoggerInterface;
use Turnkey\AuditClient\Config\FileConfig;
use Turnkey\AuditClient\Exception\WriteFailedException;
use Turnkey\AuditClient\Support\Time;

/**
 * Tier 3 (last resort) of the non-critical chain: appends JSONL to audit-{period}.jsonl.
 * FileReplayer (audit:replay) later delivers closed periods to the audit service.
 *
 * Differences from Go FileWriter (bug fixes):
 *  - failures throw instead of being swallowed, so lost events are logged by the router;
 *  - many PHP-FPM processes append to the same file, so each batch is written under
 *    flock(LOCK_EX) and the file is opened/closed per batch (no descriptor held across requests);
 *  - after acquiring the lock the writer verifies the path still points to the locked inode —
 *    if the replayer renamed the file meanwhile, it reopens, so no event lands in a claimed file;
 *  - size rotation is dropped (it never produced new files in Go).
 */
final class FileWriter implements AuditWriterInterface
{
    private const int MAX_OPEN_ATTEMPTS = 3;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly FileConfig $config,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Time::now();
    }

    public function writeBatch(array $events): array
    {
        $payload = '';
        foreach ($events as $event) {
            try {
                $payload .= $event->toJson() . "\n";
            } catch (\JsonException $e) {
                throw new WriteFailedException($this->name(), sprintf('failed to marshal event %s: %s', $event->id(), $e->getMessage()), $e);
            }
        }

        $this->ensureDirectory();
        $path = $this->config->pathFor(($this->clock)());

        $handle = $this->openLocked($path);
        try {
            $written = fwrite($handle, $payload);
            if ($written !== strlen($payload)) {
                throw new WriteFailedException($this->name(), sprintf(
                    'short write to %s (%d of %d bytes)',
                    basename($path),
                    $written === false ? 0 : $written,
                    strlen($payload),
                ));
            }
            if (!fflush($handle)) {
                throw new WriteFailedException($this->name(), 'failed to flush ' . basename($path));
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new WriteFailedException($this->name(), 'failed to fsync ' . basename($path));
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->logger->debug('audit: batch written to file', [
            'batch_size' => count($events),
            'file' => basename($path),
        ]);

        return [];
    }

    public function name(): string
    {
        return 'file';
    }

    private function ensureDirectory(): void
    {
        $dir = $this->config->directory;
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new WriteFailedException($this->name(), sprintf('failed to create directory %s', $dir));
        }
    }

    /**
     * @return resource
     */
    private function openLocked(string $path)
    {
        for ($attempt = 0; $attempt < self::MAX_OPEN_ATTEMPTS; $attempt++) {
            $existed = file_exists($path);
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                $error = error_get_last()['message'] ?? 'unknown error';
                throw new WriteFailedException($this->name(), sprintf('failed to open %s: %s', basename($path), $error));
            }
            if (!$existed) {
                @chmod($path, 0600);
            }

            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new WriteFailedException($this->name(), sprintf('failed to lock %s', basename($path)));
            }

            // The replayer may have renamed the file while we waited for the lock.
            clearstatcache(true, $path);
            $locked = fstat($handle);
            $current = @stat($path);
            if ($locked !== false && $current !== false && $locked['ino'] === $current['ino']) {
                return $handle;
            }

            flock($handle, LOCK_UN);
            fclose($handle);
        }

        throw new WriteFailedException($this->name(), sprintf('could not lock %s after %d attempts', basename($path), self::MAX_OPEN_ATTEMPTS));
    }
}
