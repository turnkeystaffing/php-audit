<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Consumer;

use Psr\Log\LoggerInterface;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Config\QueueConsumerConfig;
use Turnkey\AuditClient\Config\RedisQueueConfig;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Exception\DeliveryException;
use Turnkey\AuditClient\Http\AuditHttpClient;
use Turnkey\AuditClient\Redis\AuditRedisClientInterface;

/**
 * Drains the Redis fallback queue (filled by RedisQueueWriter) to the audit service.
 * Driven by audit:consume-queue (cron or supervisor worker).
 *
 * One batch = RPOP key count -> POST. The queue is FIFO: writers LPUSH to the head,
 * the consumer RPOPs the oldest events from the tail.
 *
 *  - accepted                            -> done
 *  - rejected with retryable=false / 4xx -> dropped with an error log
 *  - rejected with retryable=true        -> returned to the queue
 *  - network / 5xx / 429 after retries   -> whole batch returned to the tail (RPUSH, reversed
 *                                           so the oldest event is popped first again)
 *
 * Known limitation (accepted): if the process dies between RPOP and delivery/requeue,
 * that batch is lost.
 */
final class RedisQueueConsumer
{
    public function __construct(
        private readonly AuditRedisClientInterface $redis,
        private readonly AuditHttpClient $httpClient,
        private readonly HttpDeliveryProfile $profile,
        private readonly RedisQueueConfig $queueConfig,
        private readonly QueueConsumerConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Processes one batch.
     *
     * @throws \Throwable when Redis itself fails (RPOP) — the command exits non-zero
     */
    public function consumeBatch(): ConsumeResult
    {
        $raw = $this->redis->rPop($this->queueConfig->queueKey, $this->config->batchSize);
        if ($raw === []) {
            return new ConsumeResult(ConsumeResult::EMPTY);
        }

        /** @var list<FinalAuditEvent> $events */
        $events = [];
        /** @var list<string> $rawValues raw queue items, same order as $events */
        $rawValues = [];
        $dropped = 0;

        foreach ($raw as $value) {
            if (strlen($value) > $this->config->maxEventBytes) {
                $this->logger->error('audit consumer: event exceeds size limit, dropping', [
                    'raw_length' => strlen($value),
                    'max_size' => $this->config->maxEventBytes,
                ]);
                $dropped++;
                continue;
            }

            try {
                $events[] = FinalAuditEvent::fromJson($value);
                $rawValues[] = $value;
            } catch (\Throwable $e) {
                $this->logger->error('audit consumer: malformed event, dropping', [
                    'error' => $e->getMessage(),
                    'raw_length' => strlen($value),
                ]);
                $dropped++;
            }
        }

        if ($events === []) {
            return new ConsumeResult(ConsumeResult::DELIVERED, count($raw), 0, 0, $dropped);
        }

        try {
            $result = $this->httpClient->send($events, $this->profile);
        } catch (DeliveryException $e) {
            if ($e->retryable) {
                $this->requeue($rawValues);
                $this->logger->warning('audit consumer: audit service unavailable, batch returned to queue', [
                    'error' => $e->getMessage(),
                    'events' => count($rawValues),
                ]);

                return new ConsumeResult(ConsumeResult::REQUEUED, count($raw), 0, count($rawValues), $dropped);
            }

            $this->logger->error('audit consumer: batch rejected permanently, dropping', [
                'error' => $e->getMessage(),
                'http_status' => $e->httpStatus,
                'events' => count($events),
                'event_ids' => array_map(static fn (FinalAuditEvent $ev) => $ev->id(), $events),
            ]);

            return new ConsumeResult(ConsumeResult::DELIVERED, count($raw), 0, 0, $dropped + count($events));
        }

        $delivered = 0;
        $retry = [];
        foreach ($events as $i => $event) {
            $rejection = $result->rejectionFor($event->id());
            if ($rejection === null) {
                $delivered++;
                continue;
            }
            if ($rejection->retryable) {
                $retry[] = $rawValues[$i];
                continue;
            }
            $this->logger->error('audit consumer: event rejected by audit service, dropping', [
                'event_id' => $event->id(),
                'action' => $event->event->action,
                'reason' => $rejection->reason,
            ]);
            $dropped++;
        }

        if ($retry !== []) {
            $this->requeue($retry);
        }

        return new ConsumeResult(ConsumeResult::DELIVERED, count($raw), $delivered, count($retry), $dropped);
    }

    public function queueLength(): int
    {
        return $this->redis->lLen($this->queueConfig->queueKey);
    }

    /**
     * @param list<string> $values oldest first (as popped)
     */
    private function requeue(array $values): void
    {
        if ($values === []) {
            return;
        }

        try {
            // RPUSH appends in argument order, so push newest first to keep the oldest at the tail.
            $this->redis->rPush($this->queueConfig->queueKey, array_reverse($values));
            if ($this->queueConfig->queueTtlSeconds > 0) {
                $this->redis->expire($this->queueConfig->queueKey, $this->queueConfig->queueTtlSeconds);
            }
        } catch (\Throwable $e) {
            $this->logger->error('audit consumer: failed to return events to queue, events lost', [
                'error' => $e->getMessage(),
                'events' => count($values),
            ]);
        }
    }
}
