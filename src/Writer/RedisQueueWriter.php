<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Writer;

use Psr\Log\LoggerInterface;
use Turnkey\AuditClient\Config\RedisQueueConfig;
use Turnkey\AuditClient\Exception\WriteFailedException;
use Turnkey\AuditClient\Redis\AuditRedisClientInterface;

/**
 * Tier 2 of the non-critical chain: pushes events to a Redis list that
 * RedisQueueConsumer (audit:consume-queue) later drains to the audit service.
 *
 * Port of Go RedisQueueWriter: LLEN capacity check, a single atomic LPUSH, EXPIRE to bound PII retention.
 */
final class RedisQueueWriter implements AuditWriterInterface
{
    public function __construct(
        private readonly AuditRedisClientInterface $redis,
        private readonly RedisQueueConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        if ($config->queueTtlSeconds < 0) {
            $logger->warning('audit redis: queue TTL disabled — PII may persist indefinitely in Redis', [
                'queue_key' => $config->queueKey,
            ]);
        }
    }

    public function writeBatch(array $events): array
    {
        // Capacity check before serialization. An LLEN failure must not block delivery (as in Go).
        if ($this->config->maxQueueSize > 0) {
            try {
                $length = $this->redis->lLen($this->config->queueKey);
                if ($length + count($events) > $this->config->maxQueueSize) {
                    throw new WriteFailedException($this->name(), sprintf(
                        'queue capacity exceeded (%d/%d), rejecting batch of %d',
                        $length,
                        $this->config->maxQueueSize,
                        count($events),
                    ));
                }
            } catch (WriteFailedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->logger->warning('audit redis: LLEN check failed, proceeding with LPUSH', [
                    'error' => $e->getMessage(),
                    'queue_key' => $this->config->queueKey,
                ]);
            }
        }

        $values = [];
        foreach ($events as $event) {
            try {
                $values[] = $event->toJson();
            } catch (\JsonException $e) {
                throw new WriteFailedException($this->name(), sprintf('failed to marshal event %s: %s', $event->id(), $e->getMessage()), $e);
            }
        }

        try {
            $this->redis->lPush($this->config->queueKey, $values);
        } catch (\Throwable $e) {
            throw new WriteFailedException($this->name(), 'LPUSH failed: ' . $e->getMessage(), $e);
        }

        if ($this->config->queueTtlSeconds > 0) {
            try {
                $this->redis->expire($this->config->queueKey, $this->config->queueTtlSeconds);
            } catch (\Throwable $e) {
                $this->logger->warning('audit redis: failed to set queue TTL', [
                    'error' => $e->getMessage(),
                    'queue_key' => $this->config->queueKey,
                ]);
            }
        }

        $this->logger->debug('audit: batch written to redis queue', [
            'batch_size' => count($events),
            'queue_key' => $this->config->queueKey,
        ]);

        return [];
    }

    public function name(): string
    {
        return 'redis-queue';
    }
}
