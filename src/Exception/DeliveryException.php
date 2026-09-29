<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Exception;

/**
 * HTTP delivery to the audit service failed for the whole batch.
 *
 * retryable=true: transient (network, 5xx, 429) — the batch may be retried later.
 * retryable=false: permanent (4xx, token failure) — retrying will not help.
 */
final class DeliveryException extends AuditException
{
    private function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterMs = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function network(\Throwable $previous): self
    {
        return new self('audit http: network error: ' . $previous->getMessage(), true, null, null, $previous);
    }

    public static function status(int $status, bool $retryable, ?int $retryAfterMs = null): self
    {
        return new self(sprintf('audit http: delivery failed with status %d', $status), $retryable, $status, $retryAfterMs);
    }

    public static function tokenUnavailable(\Throwable $previous): self
    {
        return new self('audit http: token error: ' . $previous->getMessage(), false, null, null, $previous);
    }

    public static function encoding(\Throwable $previous): self
    {
        return new self('audit http: marshal error: ' . $previous->getMessage(), false, null, null, $previous);
    }
}
