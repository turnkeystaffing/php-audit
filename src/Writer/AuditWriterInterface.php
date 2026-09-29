<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Writer;

use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Exception\WriteFailedException;

/**
 * A tier in the non-critical delivery chain (HTTP -> Redis -> File).
 */
interface AuditWriterInterface
{
    /**
     * Persists a batch.
     *
     * @param non-empty-list<FinalAuditEvent> $events
     *
     * @return list<FinalAuditEvent> events this writer could not take but that should be passed to the
     *                               next tier (e.g. retryable per-event rejections); empty when done
     *
     * @throws WriteFailedException when the whole batch failed — the router passes it to the next tier
     */
    public function writeBatch(array $events): array;

    /**
     * Unique identifier used in logs.
     */
    public function name(): string;
}
