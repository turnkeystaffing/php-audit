<?php

declare(strict_types=1);

namespace Turnkey\AuditClient;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Turnkey\AuditClient\Config\AuditConfig;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Enricher\EnricherInterface;
use Turnkey\AuditClient\Event\AuditEvent;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Exception\CriticalAuditException;
use Turnkey\AuditClient\Exception\DeliveryException;
use Turnkey\AuditClient\Http\AuditHttpClient;
use Turnkey\AuditClient\Support\Time;
use Turnkey\AuditClient\Writer\AuditWriterInterface;

/**
 * Central coordinator (port of Go AuditRouter for the PHP request model).
 *
 * Critical events: enriched and POSTed synchronously, bypassing the buffer. Only HTTP
 * counts — there is no Redis/File fallback. If the audit service does not accept the
 * event, CriticalAuditException is thrown and the caller must reject the operation.
 *
 * Non-critical events: enriched immediately (request context is still available) and
 * buffered in memory. The buffer is flushed when it reaches batchSize and on
 * kernel.terminate / console.terminate / messenger events / reset() (see
 * AuditFlushSubscriber), with a lazily registered shutdown function as a safety net.
 * Each batch walks the writer chain in order (HTTP -> Redis -> File) until one takes it.
 */
final class AuditRouter implements AuditLoggerInterface, ResetInterface
{
    /** @var list<FinalAuditEvent> */
    private array $buffer = [];

    /** @var list<AuditWriterInterface> */
    private readonly array $writers;

    /** @var list<EnricherInterface> */
    private array $enrichers = [];

    private bool $shutdownRegistered = false;
    private bool $flushing = false;

    /**
     * @param list<AuditWriterInterface>  $writers   non-critical chain in fallback order
     * @param iterable<EnricherInterface> $enrichers executed in order
     */
    public function __construct(
        private readonly AuditConfig $config,
        private readonly AuditHttpClient $httpClient,
        private readonly HttpDeliveryProfile $criticalProfile,
        array $writers,
        private readonly LoggerInterface $logger,
        iterable $enrichers = [],
        private readonly bool $registerShutdownFunction = true,
    ) {
        if ($writers === []) {
            throw new \InvalidArgumentException('audit router: at least one writer is required');
        }
        $this->writers = $writers;

        foreach ($enrichers as $enricher) {
            $this->addEnricher($enricher);
        }
    }

    public function addEnricher(EnricherInterface $enricher): void
    {
        $this->enrichers[] = $enricher;
    }

    public function log(AuditEvent $event): void
    {
        $event->validate();

        if ($event->critical) {
            $this->deliverCritical($event);

            return;
        }

        $this->buffer[] = $this->enrich($event);
        $this->ensureShutdownFlush();

        if (count($this->buffer) >= $this->config->batchSize) {
            $this->flush();
        }
    }

    public function logCritical(AuditEvent $event): void
    {
        $event->validate();
        $this->deliverCritical($event);
    }

    public function flush(): void
    {
        if ($this->flushing || $this->buffer === []) {
            return;
        }

        $this->flushing = true;
        // Take ownership before sending: a second flush (e.g. the shutdown function) must not resend.
        $events = $this->buffer;
        $this->buffer = [];

        try {
            foreach (array_chunk($events, $this->config->batchSize) as $chunk) {
                $this->dispatchChain($chunk);
            }
        } catch (\Throwable $e) {
            // flush() runs after the response — it must never throw.
            $this->logger->error('audit: unexpected error while flushing events', [
                'error' => $e->getMessage(),
                'events' => count($events),
            ]);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Long-running runtimes (FrankenPHP/RoadRunner worker mode, kernel.reset): deliver what
     * the finished request buffered.
     */
    public function reset(): void
    {
        $this->flush();
    }

    public function bufferedCount(): int
    {
        return count($this->buffer);
    }

    /**
     * @throws CriticalAuditException
     */
    private function deliverCritical(AuditEvent $event): void
    {
        $final = $this->enrich($event);
        $eventId = $final->id();

        try {
            $result = $this->httpClient->send([$final], $this->criticalProfile);
        } catch (DeliveryException $e) {
            $this->logger->error('audit: critical event not delivered', [
                'event_id' => $eventId,
                'action' => $event->action,
                'error' => $e->getMessage(),
            ]);

            throw CriticalAuditException::notDelivered($eventId, $event->action, $e);
        }

        if (!$result->isAccepted($eventId)) {
            $rejection = $result->rejectionFor($eventId);
            $reason = $rejection !== null ? $rejection->reason : 'event id missing from accepted event_ids';
            $this->logger->error('audit: critical event rejected by audit service', [
                'event_id' => $eventId,
                'action' => $event->action,
                'reason' => $reason,
            ]);

            throw CriticalAuditException::rejected($eventId, $event->action, $reason);
        }
    }

    /**
     * @param list<FinalAuditEvent> $events
     */
    private function dispatchChain(array $events): void
    {
        foreach ($this->writers as $writer) {
            if ($events === []) {
                return;
            }

            try {
                $remaining = $writer->writeBatch($events);
            } catch (\Throwable $e) {
                $this->logger->warning('audit: writer failed, trying next', [
                    'writer' => $writer->name(),
                    'error' => $e->getMessage(),
                    'batch_size' => count($events),
                ]);
                continue;
            }

            if ($remaining !== []) {
                $this->logger->info('audit: passing retryable events to next writer', [
                    'writer' => $writer->name(),
                    'count' => count($remaining),
                ]);
            }
            $events = $remaining;
        }

        if ($events !== []) {
            $this->logger->error('audit: all writers failed, events lost', [
                'writer_count' => count($this->writers),
                'batch_size' => count($events),
                'event_ids' => array_map(static fn (FinalAuditEvent $e) => $e->id(), $events),
            ]);
        }
    }

    private function enrich(AuditEvent $event): FinalAuditEvent
    {
        // Enrichers work on a copy: the caller's event is left untouched.
        $copy = clone $event;
        $budgetNs = $this->config->enricherBudgetMs * 1_000_000;
        $start = hrtime(true);

        foreach ($this->enrichers as $index => $enricher) {
            try {
                $enricher->enrich($copy);
            } catch (\Throwable $e) {
                $this->logger->warning('audit: enricher failed', [
                    'enricher' => $enricher::class,
                    'enricher_index' => $index,
                    'error' => $e->getMessage(),
                    'action' => $event->action,
                ]);
            }
        }

        $elapsedNs = hrtime(true) - $start;
        if ($this->enrichers !== [] && $elapsedNs > $budgetNs) {
            $this->logger->warning('audit: enrichers exceeded time budget', [
                'duration_ms' => round($elapsedNs / 1_000_000, 2),
                'budget_ms' => $this->config->enricherBudgetMs,
                'action' => $event->action,
            ]);
        }

        // Enricher-added "enrichment.*" keys go to enrichment metadata; the rest stays app context.
        $context = [];
        $enrichment = [];
        foreach ($copy->contextData as $key => $value) {
            if (str_starts_with($key, 'enrichment.')) {
                $enrichment[$key] = $value;
            } else {
                $context[$key] = $value;
            }
        }

        return new FinalAuditEvent($copy->withContextData($context), $enrichment, Time::now());
    }

    private function ensureShutdownFlush(): void
    {
        if ($this->shutdownRegistered || !$this->registerShutdownFunction) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush();
        });
    }
}
