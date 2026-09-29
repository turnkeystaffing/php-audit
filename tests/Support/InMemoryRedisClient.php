<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Support;

use Turnkey\AuditClient\Redis\AuditRedisClientInterface;

/**
 * Redis list semantics in memory: index 0 is the head (LPUSH side), the last element is the tail (RPOP side).
 */
final class InMemoryRedisClient implements AuditRedisClientInterface
{
    /** @var array<string, list<string>> */
    public array $lists = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public ?\Throwable $failLPush = null;
    public ?\Throwable $failRPush = null;
    public ?\Throwable $failRPop = null;
    public ?\Throwable $failLLen = null;
    public ?\Throwable $failExpire = null;
    public ?\Throwable $failPing = null;

    public int $lPushCalls = 0;

    public function lPush(string $key, array $values): int
    {
        $this->lPushCalls++;
        if ($this->failLPush !== null) {
            throw $this->failLPush;
        }
        $this->lists[$key] ??= [];
        foreach ($values as $value) {
            array_unshift($this->lists[$key], $value);
        }

        return count($this->lists[$key]);
    }

    public function rPush(string $key, array $values): int
    {
        if ($this->failRPush !== null) {
            throw $this->failRPush;
        }
        $this->lists[$key] ??= [];
        foreach ($values as $value) {
            $this->lists[$key][] = $value;
        }

        return count($this->lists[$key]);
    }

    public function rPop(string $key, int $count): array
    {
        if ($this->failRPop !== null) {
            throw $this->failRPop;
        }
        $popped = [];
        while ($count-- > 0 && ($this->lists[$key] ?? []) !== []) {
            $popped[] = array_pop($this->lists[$key]);
        }

        return $popped;
    }

    public function lLen(string $key): int
    {
        if ($this->failLLen !== null) {
            throw $this->failLLen;
        }

        return count($this->lists[$key] ?? []);
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        if ($this->failExpire !== null) {
            throw $this->failExpire;
        }
        $this->ttls[$key] = $ttlSeconds;

        return true;
    }

    public function ping(): void
    {
        if ($this->failPing !== null) {
            throw $this->failPing;
        }
    }

    public function getPrefix(): string
    {
        return '';
    }

    /** @return list<string> */
    public function items(string $key): array
    {
        return $this->lists[$key] ?? [];
    }
}
