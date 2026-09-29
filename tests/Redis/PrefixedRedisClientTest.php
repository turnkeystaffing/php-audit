<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Redis;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\Redis\PrefixedRedisClient;

final class PrefixedRedisClientTest extends TestCase
{
    public function testPrefixesEveryKey(): void
    {
        $predis = $this->fakePredis(rpop: ['a', 'b']);
        $client = new PrefixedRedisClient($predis, 'getnative:');

        $client->lPush('q', ['1', '2']);
        $client->rPush('q', ['3']);
        $popped = $client->rPop('q', 2);
        $client->lLen('q');
        $client->expire('q', 60);
        $client->ping();

        self::assertSame(['a', 'b'], $popped);
        self::assertSame([
            ['lpush', 'getnative:q', ['1', '2']],
            ['rpush', 'getnative:q', ['3']],
            ['rpop', 'getnative:q', 2],
            ['llen', 'getnative:q'],
            ['expire', 'getnative:q', 60],
            ['ping'],
        ], $predis->calls);
        self::assertSame('getnative:', $client->getPrefix());
    }

    public function testEmptyPrefixUsesKeysAsIs(): void
    {
        $predis = $this->fakePredis();
        (new PrefixedRedisClient($predis))->lLen('audit:fallback:queue');

        self::assertSame([['llen', 'audit:fallback:queue']], $predis->calls);
    }

    public function testRpopNormalizesResponses(): void
    {
        self::assertSame([], (new PrefixedRedisClient($this->fakePredis(rpop: null)))->rPop('q', 10));
        self::assertSame(['only'], (new PrefixedRedisClient($this->fakePredis(rpop: 'only')))->rPop('q', 1));
        self::assertSame([], (new PrefixedRedisClient($this->fakePredis()))->rPop('q', 0));
    }

    /**
     * @param list<string>|string|null $rpop
     *
     * @return object{calls: list<array<mixed>>}
     */
    private function fakePredis(array|string|null $rpop = []): object
    {
        return new class ($rpop) {
            /** @var list<array<mixed>> */
            public array $calls = [];

            /** @param list<string>|string|null $rpop */
            public function __construct(private array|string|null $rpop)
            {
            }

            /** @param array<mixed> $args */
            public function __call(string $name, array $args): mixed
            {
                $this->calls[] = [$name, ...$args];

                return match ($name) {
                    'rpop' => $this->rpop,
                    'lpush', 'rpush', 'llen' => 1,
                    'expire' => 1,
                    default => 'PONG',
                };
            }
        };
    }
}
