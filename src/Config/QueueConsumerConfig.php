<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Config;

final readonly class QueueConsumerConfig
{
    /**
     * @param int $batchSize     events per RPOP / HTTP request
     * @param int $maxEventBytes queue items larger than this are dropped (defense against OOM)
     */
    public function __construct(
        public int $batchSize = 100,
        public int $maxEventBytes = 1_048_576,
    ) {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new \InvalidArgumentException(sprintf('audit consumer: batch size must be between 1 and 1000, got %d', $batchSize));
        }
        if ($maxEventBytes < 1) {
            throw new \InvalidArgumentException('audit consumer: max event size must be positive');
        }
    }
}
