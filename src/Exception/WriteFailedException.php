<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Exception;

/**
 * A writer failed to persist a batch. The router passes the batch on to the next writer in the chain.
 */
final class WriteFailedException extends AuditException
{
    public function __construct(
        public readonly string $writer,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('%s writer: %s', $writer, $message), 0, $previous);
    }
}
