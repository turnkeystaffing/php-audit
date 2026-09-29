<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Event;

use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Turnkey\AuditClient\Exception\InvalidAuditEventException;

/**
 * A security-relevant action captured before enrichment.
 *
 * Core fields are readonly, so enrichers cannot mutate the event identity
 * (the Go version enforces this at runtime). Enrichers may only add new keys
 * to the context via addContext(). Construct via EventBuilder.
 */
final class AuditEvent
{
    private const string NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /**
     * Application-specific context ("metadata" in the wire format).
     *
     * @var array<string, mixed>
     */
    public private(set) array $contextData;

    /**
     * @param array<string, mixed> $contextData
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly string $action,
        public readonly ?Outcome $outcome,
        public readonly \DateTimeImmutable $timestamp,
        public readonly Uuid $requestId,
        public readonly ?Uuid $userId = null,
        public readonly string $resourceType = '',
        public readonly ?Uuid $resourceId = null,
        public readonly string $failureReason = '',
        public readonly ?string $ipAddress = null,
        public readonly string $userAgent = '',
        public readonly bool $critical = false,
        array $contextData = [],
    ) {
        $this->contextData = $contextData;
    }

    /**
     * Adds a context key. Existing keys are never overwritten.
     *
     * @return bool true if the key was added, false if it already existed
     */
    public function addContext(string $key, mixed $value): bool
    {
        if (array_key_exists($key, $this->contextData)) {
            return false;
        }

        $this->contextData[$key] = $value;

        return true;
    }

    /**
     * Returns a copy with the context replaced.
     *
     * @internal used by the router to split enricher output from application context
     *
     * @param array<string, mixed> $contextData
     */
    public function withContextData(array $contextData): self
    {
        $copy = clone $this;
        $copy->contextData = $contextData;

        return $copy;
    }

    /**
     * @throws InvalidAuditEventException
     */
    public function validate(): void
    {
        if ($this->action === '') {
            throw new InvalidAuditEventException('audit event action is required');
        }

        if ($this->outcome === null) {
            throw new InvalidAuditEventException('invalid audit event outcome: (must be success, failure, or partial)');
        }

        if ($this->ipAddress !== null && $this->ipAddress !== ''
            && filter_var($this->ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidAuditEventException(sprintf('invalid IP address: %s', $this->ipAddress));
        }

        if ($this->requestId instanceof NilUuid || $this->requestId->toRfc4122() === self::NIL_UUID) {
            throw new InvalidAuditEventException('audit event request ID is required');
        }
    }
}
