<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Config;

/**
 * Pipeline configuration for the in-request buffer.
 *
 * Go equivalents that do not apply to a per-request PHP process (BufferSize,
 * FlushInterval, AlertThreshold, ShutdownTimeout, DispatchStrategy) are omitted:
 * the buffer lives for one request and is flushed on batchSize or kernel.terminate.
 */
final readonly class AuditConfig
{
    public function __construct(
        public bool $enabled = true,
        public int $batchSize = 100,
        public int $enricherBudgetMs = 100,
    ) {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException(sprintf('audit: batch size must be between 1 and 1000, got %d', $batchSize));
        }
        if ($enricherBudgetMs < 1) {
            throw new \InvalidArgumentException(sprintf('audit: enricher budget must be >= 1ms, got %d', $enricherBudgetMs));
        }
    }
}
