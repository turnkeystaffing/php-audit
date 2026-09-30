<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Replay;

use Psr\Log\LoggerInterface;
use Turnkey\AuditClient\Config\FileConfig;
use Turnkey\AuditClient\Config\FileReplayConfig;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Exception\DeliveryException;
use Turnkey\AuditClient\Http\AuditHttpClient;
use Turnkey\AuditClient\Support\Time;

/**
 * Delivers JSONL files written by FileWriter to the audit service. Driven by audit:replay.
 *
 * Differences from Go FileReplayer (bug fixes):
 *  - files of the current period (and files modified within minFileAge) are never touched,
 *    so the replayer no longer deletes a file that is still being appended to;
 *  - a file is claimed by flock + rename to "*.replaying" before reading, so late writers
 *    reopen a fresh file instead of appending to one being replayed;
 *  - progress (byte offset) is stored in "*.replaying.offset" after every delivered batch,
 *    so an interrupted replay resumes without re-sending delivered batches;
 *  - permanent rejections (4xx, retryable=false) are dropped with an error log instead of
 *    blocking the file; there is no ".failed/" quarantine or retry counter.
 *
 * A batch with retryable per-event rejections is not advanced past: the whole batch is
 * re-sent on the next pass (the audit service deduplicates by event_id).
 */
final class FileReplayer
{
    private const string LOCK_FILE = '.replay.lock';
    private const string CLAIMED_SUFFIX = '.replaying';
    private const string OFFSET_SUFFIX = '.offset';
    private const string FILE_PATTERN = '/^audit-(\d{4}-\d{2}-\d{2}(?:-\d{2})?)\.jsonl(\.replaying)?$/';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly FileConfig $fileConfig,
        private readonly AuditHttpClient $httpClient,
        private readonly HttpDeliveryProfile $profile,
        private readonly FileReplayConfig $config,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Time::now();
    }

    /**
     * One pass over the directory. Holds an exclusive non-blocking lock for its duration.
     */
    public function replayOnce(): ReplayResult
    {
        $dir = $this->fileConfig->directory;
        if (!is_dir($dir)) {
            return new ReplayResult(ReplayResult::DONE);
        }

        $lock = @fopen($dir . '/' . self::LOCK_FILE, 'c');
        if ($lock === false) {
            throw new \RuntimeException(sprintf('audit replay: cannot open lock file in %s', $dir));
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return new ReplayResult(ReplayResult::LOCKED);
        }

        try {
            return $this->replayFiles();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function replayFiles(): ReplayResult
    {
        $files = $this->eligibleFiles();
        if ($files === []) {
            return new ReplayResult(ReplayResult::DONE);
        }

        try {
            $this->httpClient->health();
        } catch (DeliveryException $e) {
            $this->logger->debug('audit replay: audit service unhealthy, skipping pass', ['error' => $e->getMessage()]);

            return new ReplayResult(ReplayResult::UNHEALTHY);
        }

        $completed = 0;
        $delivered = 0;
        $dropped = 0;

        foreach ($files as $name) {
            $claimed = $this->claim($name);
            if ($claimed === null) {
                continue;
            }

            $outcome = $this->replayFile($claimed);
            $delivered += $outcome['delivered'];
            $dropped += $outcome['dropped'];

            if (!$outcome['finished']) {
                return new ReplayResult(ReplayResult::RETRY_LATER, $completed, $delivered, $dropped);
            }
            $completed++;
        }

        return new ReplayResult(ReplayResult::DONE, $completed, $delivered, $dropped);
    }

    /**
     * @return list<string> file names, oldest period first; claimed files before new ones of the same period
     */
    private function eligibleFiles(): array
    {
        $entries = @scandir($this->fileConfig->directory);
        if ($entries === false) {
            throw new \RuntimeException(sprintf('audit replay: cannot read directory %s', $this->fileConfig->directory));
        }

        $now = ($this->clock)();
        $currentPeriods = [
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d-H'),
        ];

        $files = [];
        foreach ($entries as $name) {
            if (preg_match(self::FILE_PATTERN, $name, $m) !== 1) {
                continue;
            }
            $isClaimed = ($m[2] ?? '') !== '';
            $path = $this->fileConfig->directory . '/' . $name;
            if (!is_file($path)) {
                continue;
            }

            if (!$isClaimed) {
                if (in_array($m[1], $currentPeriods, true)) {
                    continue; // still the active file
                }
                $mtime = @filemtime($path);
                if ($mtime !== false && $now->getTimestamp() - $mtime < $this->config->minFileAgeSeconds) {
                    continue; // recently written
                }
            }

            $files[] = $name;
        }

        usort($files, static function (string $a, string $b): int {
            $baseA = str_replace(self::CLAIMED_SUFFIX, '', $a);
            $baseB = str_replace(self::CLAIMED_SUFFIX, '', $b);

            return [$baseA, !str_ends_with($a, self::CLAIMED_SUFFIX)] <=> [$baseB, !str_ends_with($b, self::CLAIMED_SUFFIX)];
        });

        return $files;
    }

    /**
     * Claims a file by renaming it to *.replaying under the writer's lock.
     *
     * @return string|null path of the claimed file, or null to skip it for now
     */
    private function claim(string $name): ?string
    {
        $path = $this->fileConfig->directory . '/' . $name;
        if (str_ends_with($name, self::CLAIMED_SUFFIX)) {
            return $path;
        }

        $target = $path . self::CLAIMED_SUFFIX;
        if (file_exists($target)) {
            // An earlier claim of the same period is still pending; finish that one first.
            return null;
        }

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return null;
        }
        try {
            // Wait for any FileWriter currently appending to this file.
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            if (!@rename($path, $target)) {
                $this->logger->warning('audit replay: failed to claim file', ['file' => $name]);

                return null;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $target;
    }

    /**
     * @return array{finished: bool, delivered: int, dropped: int}
     */
    private function replayFile(string $path): array
    {
        $offsetPath = $path . self::OFFSET_SUFFIX;
        $offset = $this->readOffset($offsetPath);
        $file = basename($path);

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            $this->logger->warning('audit replay: cannot open file', ['file' => $file]);

            return ['finished' => false, 'delivered' => 0, 'dropped' => 0];
        }

        $delivered = 0;
        $dropped = 0;

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                $offset = 0;
                rewind($handle);
            }

            $lineNumber = 0;
            /** @var list<FinalAuditEvent> $batch */
            $batch = [];

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                if (strlen($trimmed) > $this->config->maxLineBytes) {
                    $this->logger->error('audit replay: line exceeds size limit, skipping', ['file' => $file, 'line_bytes' => strlen($trimmed)]);
                    $dropped++;
                    continue;
                }

                try {
                    $batch[] = FinalAuditEvent::fromJson($trimmed);
                } catch (\Throwable $e) {
                    $this->logger->error('audit replay: malformed JSON line, skipping', [
                        'file' => $file,
                        'line' => $lineNumber,
                        'error' => $e->getMessage(),
                    ]);
                    $dropped++;
                    continue;
                }

                if (count($batch) >= $this->config->batchSize) {
                    $sent = $this->sendBatch($batch, $file);
                    if ($sent === null) {
                        return ['finished' => false, 'delivered' => $delivered, 'dropped' => $dropped];
                    }
                    $delivered += $sent['delivered'];
                    $dropped += $sent['dropped'];
                    $this->writeOffset($offsetPath, (int) ftell($handle));
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $sent = $this->sendBatch($batch, $file);
                if ($sent === null) {
                    return ['finished' => false, 'delivered' => $delivered, 'dropped' => $dropped];
                }
                $delivered += $sent['delivered'];
                $dropped += $sent['dropped'];
            }
        } finally {
            fclose($handle);
        }

        @unlink($path);
        @unlink($offsetPath);

        $this->logger->info('audit replay: file replayed and deleted', [
            'file' => $file,
            'delivered' => $delivered,
            'dropped' => $dropped,
        ]);

        return ['finished' => true, 'delivered' => $delivered, 'dropped' => $dropped];
    }

    /**
     * @param list<FinalAuditEvent> $batch
     *
     * @return array{delivered: int, dropped: int}|null null = transient failure, stop and retry later
     */
    private function sendBatch(array $batch, string $file): ?array
    {
        try {
            $result = $this->httpClient->send($batch, $this->profile);
        } catch (DeliveryException $e) {
            if ($e->retryable) {
                $this->logger->warning('audit replay: transient delivery failure, will retry', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            $this->logger->error('audit replay: batch rejected permanently, dropping', [
                'file' => $file,
                'error' => $e->getMessage(),
                'http_status' => $e->httpStatus,
                'events' => count($batch),
            ]);

            return ['delivered' => 0, 'dropped' => count($batch)];
        }

        $delivered = 0;
        $dropped = 0;
        foreach ($batch as $event) {
            $rejection = $result->rejectionFor($event->id());
            if ($rejection === null) {
                $delivered++;
                continue;
            }
            if ($rejection->retryable) {
                $this->logger->warning('audit replay: retryable rejection, batch will be re-sent', [
                    'file' => $file,
                    'event_id' => $event->id(),
                    'reason' => $rejection->reason,
                ]);

                return null;
            }
            $this->logger->error('audit replay: event rejected by audit service, dropping', [
                'file' => $file,
                'event_id' => $event->id(),
                'reason' => $rejection->reason,
            ]);
            $dropped++;
        }

        return ['delivered' => $delivered, 'dropped' => $dropped];
    }

    private function readOffset(string $offsetPath): int
    {
        $raw = @file_get_contents($offsetPath);
        if ($raw === false) {
            return 0;
        }
        $raw = trim($raw);

        return ctype_digit($raw) ? (int) $raw : 0;
    }

    private function writeOffset(string $offsetPath, int $offset): void
    {
        $tmp = $offsetPath . '.tmp';
        if (@file_put_contents($tmp, (string) $offset) === false || !@rename($tmp, $offsetPath)) {
            $this->logger->warning('audit replay: failed to persist offset', ['file' => basename($offsetPath)]);
        }
    }
}
