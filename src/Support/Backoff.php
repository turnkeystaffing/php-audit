<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Support;

/**
 * @internal
 */
final class Backoff
{
    private function __construct()
    {
    }

    /**
     * Exponential backoff: base * 2^attempt, capped at max (same algorithm as Go calcBackoff).
     */
    public static function delay(int $base, int $max, int $attempt): int
    {
        $delay = $base;
        for ($i = 0; $i < $attempt; $i++) {
            $delay *= 2;
            if ($delay >= $max) {
                return $max;
            }
        }

        return min($delay, $max);
    }
}
