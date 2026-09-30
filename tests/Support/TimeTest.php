<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Support;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\Support\Backoff;
use Turnkey\AuditClient\Support\Time;

final class TimeTest extends TestCase
{
    public function testFormatsUtcWithMicroseconds(): void
    {
        $time = new \DateTimeImmutable('2026-09-29 12:00:00.5', new \DateTimeZone('Europe/Kyiv'));

        self::assertSame('2026-09-29T09:00:00.500000Z', Time::format($time));
    }

    public function testParsesVariousPrecisionsAndOffsets(): void
    {
        self::assertSame('2026-09-29T10:00:00.000000Z', Time::format(Time::parse('2026-09-29T10:00:00Z')));
        self::assertSame('2026-09-29T10:00:00.123456Z', Time::format(Time::parse('2026-09-29T10:00:00.123456789Z')));
        self::assertSame('2026-09-29T07:00:00.100000Z', Time::format(Time::parse('2026-09-29T10:00:00.1+03:00')));
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Time::parse('yesterday');
    }

    public function testBackoffDoublesAndCaps(): void
    {
        self::assertSame(100, Backoff::delay(100, 5000, 0));
        self::assertSame(200, Backoff::delay(100, 5000, 1));
        self::assertSame(400, Backoff::delay(100, 5000, 2));
        self::assertSame(5000, Backoff::delay(100, 5000, 10));
        self::assertSame(300, Backoff::delay(500, 300, 0));
    }
}
