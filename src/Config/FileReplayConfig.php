<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Config;

final readonly class FileReplayConfig
{
    /**
     * @param int $batchSize         events per HTTP request
     * @param int $minFileAgeSeconds files modified more recently than this are skipped (still being written)
     * @param int $maxLineBytes      lines longer than this are skipped
     */
    public function __construct(
        public int $batchSize = 100,
        public int $minFileAgeSeconds = 60,
        public int $maxLineBytes = 1_048_576,
    ) {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException(sprintf('audit replay: batch size must be between 1 and 1000, got %d', $batchSize));
        }
        if ($minFileAgeSeconds < 0) {
            throw new \InvalidArgumentException('audit replay: min file age must not be negative');
        }
        if ($maxLineBytes < 1) {
            throw new \InvalidArgumentException('audit replay: max line size must be positive');
        }
    }
}
