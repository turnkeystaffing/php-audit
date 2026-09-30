<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Config;

/**
 * Last-resort JSONL storage shared by FileWriter and FileReplayer.
 */
final readonly class FileConfig
{
    public const string ROTATION_DAILY = 'daily';
    public const string ROTATION_HOURLY = 'hourly';

    public string $directory;

    public function __construct(string $directory, public string $rotation = self::ROTATION_DAILY)
    {
        if ($directory === '') {
            throw new \InvalidArgumentException('audit file: directory is required');
        }
        if ($rotation !== self::ROTATION_DAILY && $rotation !== self::ROTATION_HOURLY) {
            throw new \InvalidArgumentException(
                sprintf('audit file: rotation must be "daily" or "hourly", got "%s"', $rotation),
            );
        }

        $this->directory = rtrim($directory, '/');
    }

    /**
     * Period suffix for a point in time: "2026-09-29" (daily) or "2026-09-29-10" (hourly).
     */
    public function period(\DateTimeImmutable $time): string
    {
        $utc = $time->setTimezone(new \DateTimeZone('UTC'));

        return $this->rotation === self::ROTATION_HOURLY ? $utc->format('Y-m-d-H') : $utc->format('Y-m-d');
    }

    public function pathFor(\DateTimeImmutable $time): string
    {
        return sprintf('%s/audit-%s.jsonl', $this->directory, $this->period($time));
    }
}
