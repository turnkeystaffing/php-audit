<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Fluent builder for audit events.
 *
 *     $event = (new EventBuilder())
 *         ->withUser($userId)
 *         ->withAction('login_success')
 *         ->withResource('session', $sessionId)
 *         ->withSuccess()
 *         ->withHttpContext($ip, $userAgent)
 *         ->withContext(['email' => $email])
 *         ->build();
 *
 * The builder does not validate — validation happens in the router.
 */
final class EventBuilder
{
    private Uuid $id;
    private \DateTimeImmutable $timestamp;
    private Uuid $requestId;
    private ?Uuid $userId = null;
    private string $action = '';
    private string $resourceType = '';
    private ?Uuid $resourceId = null;
    private ?Outcome $outcome = null;
    private string $failureReason = '';
    private ?string $ipAddress = null;
    private string $userAgent = '';
    private bool $critical = false;

    /** @var array<string, mixed> */
    private array $contextData = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->timestamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        // Overridden by withRequestId() when the request carries a correlation ID.
        $this->requestId = Uuid::v7();
    }

    public function withUser(Uuid $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function withAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function withResource(string $resourceType, ?Uuid $resourceId = null): self
    {
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;

        return $this;
    }

    public function withSuccess(): self
    {
        $this->outcome = Outcome::Success;

        return $this;
    }

    public function withFailure(string $reason): self
    {
        $this->outcome = Outcome::Failure;
        $this->failureReason = $reason;

        return $this;
    }

    public function withPartial(string $reason): self
    {
        $this->outcome = Outcome::Partial;
        $this->failureReason = $reason;

        return $this;
    }

    public function withHttpContext(string $ipAddress, string $userAgent): self
    {
        if ($ipAddress !== '') {
            $this->ipAddress = $ipAddress;
        }
        $this->userAgent = $userAgent;

        return $this;
    }

    public function withRequestId(Uuid $requestId): self
    {
        $this->requestId = $requestId;

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function withContext(array $data): self
    {
        foreach ($data as $key => $value) {
            $this->contextData[(string) $key] = $value;
        }

        return $this;
    }

    public function withContextValue(string $key, mixed $value): self
    {
        $this->contextData[$key] = $value;

        return $this;
    }

    /**
     * Marks the event as compliance-critical: it is delivered synchronously over HTTP
     * and log() throws CriticalAuditException when delivery fails.
     */
    public function withCritical(): self
    {
        $this->critical = true;

        return $this;
    }

    public function build(): AuditEvent
    {
        return new AuditEvent(
            id: $this->id,
            action: $this->action,
            outcome: $this->outcome,
            timestamp: $this->timestamp,
            requestId: $this->requestId,
            userId: $this->userId,
            resourceType: $this->resourceType,
            resourceId: $this->resourceId,
            failureReason: $this->failureReason,
            ipAddress: $this->ipAddress,
            userAgent: $this->userAgent,
            critical: $this->critical,
            contextData: $this->contextData,
        );
    }
}
