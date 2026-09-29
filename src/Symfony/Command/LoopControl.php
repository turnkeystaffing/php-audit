<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Symfony\Command;

/**
 * Stop conditions and interruptible sleep shared by the long-running commands.
 *
 * @internal
 */
final class LoopControl
{
    private bool $stopRequested = false;
    private readonly float $startedAt;

    /**
     * @param int $timeLimitSeconds 0 = unlimited
     * @param int $memoryLimitBytes 0 = unlimited
     */
    public function __construct(
        private readonly int $timeLimitSeconds,
        private readonly int $memoryLimitBytes,
    ) {
        $this->startedAt = microtime(true);
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    public function shouldStop(): bool
    {
        if ($this->stopRequested) {
            return true;
        }
        if ($this->timeLimitSeconds > 0 && microtime(true) - $this->startedAt >= $this->timeLimitSeconds) {
            return true;
        }

        return $this->memoryLimitBytes > 0 && memory_get_usage(true) >= $this->memoryLimitBytes;
    }

    /**
     * Sleeps in short slices so a stop request or the time limit ends the wait early.
     */
    public function sleep(int $milliseconds): void
    {
        $deadline = microtime(true) + $milliseconds / 1000;
        while (!$this->shouldStop()) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return;
            }
            usleep((int) (min($left, 0.25) * 1_000_000));
        }
    }
}
