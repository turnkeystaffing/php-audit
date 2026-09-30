<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Enricher;

use Turnkey\AuditClient\Event\AuditEvent;

/**
 * Adds contextual metadata to an event.
 *
 * Enrichers run synchronously inside log(), while the originating request is still
 * current. They may only add keys via $event->addContext() (core fields are readonly
 * and existing keys are never overwritten). Keys prefixed "enrichment." are moved
 * into FinalAuditEvent::$enrichmentMetadata; other keys stay in the event metadata.
 *
 * Enrichers must be fast (the total budget is AuditConfig::$enricherBudgetMs),
 * idempotent and tolerate missing data. Exceptions are logged and do not stop the event.
 */
interface EnricherInterface
{
    public function enrich(AuditEvent $event): void;
}
