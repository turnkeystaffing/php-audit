<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Config;

/**
 * Redis fallback queue shared by RedisQueueWriter (producer) and RedisQueueConsumer.
 * The key is prefixed by PrefixedRedisClient, e.g. "getnative:audit:fallback:queue".
 */
final readonly class RedisQueueConfig
{
    public const string DEFAULT_QUEUE_KEY = 'audit:fallback:queue';
    public const int DEFAULT_TTL_SECONDS = 7 * 24 * 3600;

    public int $queueTtlSeconds;

    /**
     * @param int $maxQueueSize    reject writes once the queue holds this many events (0 = unlimited)
     * @param int $queueTtlSeconds TTL on the queue key to bound PII retention (0 = default 7 days, <0 = no TTL)
     */
    public function __construct(
        public string $queueKey = self::DEFAULT_QUEUE_KEY,
        public int $maxQueueSize = 10000,
        int $queueTtlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        if ($queueKey === '') {
            throw new \InvalidArgumentException('audit redis: queue key is required');
        }
        if ($maxQueueSize < 0) {
            throw new \InvalidArgumentException('audit redis: max queue size must not be negative');
        }

        $this->queueTtlSeconds = $queueTtlSeconds === 0 ? self::DEFAULT_TTL_SECONDS : $queueTtlSeconds;
    }
}
