<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Redis;

/**
 * Minimal Redis list API needed by the fallback queue.
 * Implementations prefix every key (see PrefixedRedisClient).
 */
interface AuditRedisClientInterface
{
    /**
     * @param non-empty-list<string> $values
     *
     * @return int list length after the push
     */
    public function lPush(string $key, array $values): int;

    /**
     * @param non-empty-list<string> $values
     *
     * @return int list length after the push
     */
    public function rPush(string $key, array $values): int;

    /**
     * Pops up to $count elements from the tail (Redis >= 6.2).
     *
     * @return list<string> empty when the list is empty or missing
     */
    public function rPop(string $key, int $count): array;

    public function lLen(string $key): int;

    public function expire(string $key, int $ttlSeconds): bool;

    /**
     * @throws \Throwable when Redis is unreachable
     */
    public function ping(): void;

    public function getPrefix(): string;
}
