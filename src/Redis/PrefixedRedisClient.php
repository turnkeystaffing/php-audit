<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Redis;

/**
 * Redis client wrapper that transparently prefixes all keys.
 *
 * Same pattern as Turnkey\AuthClient\Redis\PrefixedClient: the application passes
 * its Predis\Client (same instance/credentials for every library) and a prefix, e.g.
 *
 *     $predis = new \Predis\Client($redisUrl);
 *     $auditRedis = new PrefixedRedisClient($predis, 'getnative:');
 *
 * Unlike authclient there is no default prefix: an empty prefix means keys are used as-is.
 */
final class PrefixedRedisClient implements AuditRedisClientInterface
{
    /**
     * @param object $client Predis\ClientInterface (commands are invoked by name)
     */
    public function __construct(
        private readonly object $client,
        private readonly string $prefix = '',
    ) {
    }

    public function lPush(string $key, array $values): int
    {
        return (int) $this->client->lpush($this->prefixKey($key), $values);
    }

    public function rPush(string $key, array $values): int
    {
        return (int) $this->client->rpush($this->prefixKey($key), $values);
    }

    public function rPop(string $key, int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $result = $this->client->rpop($this->prefixKey($key), $count);

        if ($result === null || $result === false) {
            return [];
        }
        if (is_string($result)) {
            return [$result];
        }
        if (!is_array($result)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $result));
    }

    public function lLen(string $key): int
    {
        return (int) $this->client->llen($this->prefixKey($key));
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return (bool) $this->client->expire($this->prefixKey($key), $ttlSeconds);
    }

    public function ping(): void
    {
        $this->client->ping();
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    private function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }
}
