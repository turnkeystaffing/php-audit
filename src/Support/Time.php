<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Support;

/**
 * RFC 3339 timestamps compatible with Go's time.Time JSON encoding.
 *
 * @internal
 */
final class Time
{
    private function __construct()
    {
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Formats in UTC with microsecond precision, e.g. "2026-09-29T10:00:00.123456Z".
     * Go parses this as RFC 3339.
     */
    public static function format(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * Parses RFC 3339 with any fractional precision (Go emits up to 9 digits; PHP keeps 6).
     */
    public static function parse(string $value): \DateTimeImmutable
    {
        $matched = preg_match(
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:\d{2})$/i',
            $value,
            $m,
        );
        if ($matched !== 1) {
            throw new \InvalidArgumentException(sprintf('invalid RFC 3339 timestamp: "%s"', $value));
        }

        $fraction = substr(str_pad($m[2], 6, '0'), 0, 6);
        $zone = strtoupper($m[3]) === 'Z' ? '+00:00' : $m[3];

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $m[1] . '.' . $fraction . $zone);
        if ($parsed === false) {
            throw new \InvalidArgumentException(sprintf('invalid RFC 3339 timestamp: "%s"', $value));
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }
}
