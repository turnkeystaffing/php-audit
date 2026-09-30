<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Config;

/**
 * Timeouts and retry policy for one HTTP delivery.
 *
 * Two profiles are used: batch() for non-critical batches (flushed after the
 * response, so it must not hold the FPM worker for long) and critical() for
 * synchronous compliance events (more patience, since failure rejects the operation).
 */
final readonly class HttpDeliveryProfile
{
    /**
     * @param float $timeoutSeconds   per-attempt timeout (connect + response)
     * @param int   $maxRetries       retries after the first attempt for network errors, 5xx and 429
     * @param int   $retryBaseDelayMs exponential backoff base: base * 2^attempt, capped at 5s
     * @param int   $maxRetryAfterMs  cap for a server-provided Retry-After on 429
     */
    public function __construct(
        public float $timeoutSeconds,
        public int $maxRetries,
        public int $retryBaseDelayMs = 100,
        public int $maxRetryAfterMs = 1000,
    ) {
        if ($timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('audit http: timeout must be positive');
        }
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('audit http: max retries must not be negative');
        }
        if ($retryBaseDelayMs < 0 || $maxRetryAfterMs < 0) {
            throw new \InvalidArgumentException('audit http: retry delays must not be negative');
        }
    }

    /** Non-critical batches: 2s timeout, 1 retry. */
    public static function batch(): self
    {
        return new self(timeoutSeconds: 2.0, maxRetries: 1);
    }

    /** Critical events: 3s timeout, 2 retries. */
    public static function critical(): self
    {
        return new self(timeoutSeconds: 3.0, maxRetries: 2);
    }
}
