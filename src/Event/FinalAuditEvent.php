<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Event;

use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Turnkey\AuditClient\Support\Time;

/**
 * A fully enriched audit event ready for delivery.
 *
 * The JSON form (jsonSerialize / fromArray) is the Redis queue and JSONL file
 * format. It is byte-compatible with Go's json.Marshal(FinalAuditEvent):
 * embedded AuditEvent fields at the top level, "result" for the outcome,
 * "created_at" for the timestamp and "metadata" for the context.
 */
final class FinalAuditEvent implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $enrichmentMetadata keys added by enrichers (prefixed "enrichment.")
     */
    public function __construct(
        public readonly AuditEvent $event,
        public readonly array $enrichmentMetadata,
        public readonly \DateTimeImmutable $enrichedAt,
        public readonly ?\DateTimeImmutable $writtenAt = null,
    ) {
    }

    public function id(): string
    {
        return $this->event->id->toRfc4122();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $e = $this->event;

        return [
            'id' => $e->id->toRfc4122(),
            'user_id' => $e->userId?->toRfc4122(),
            'action' => $e->action,
            'resource_type' => $e->resourceType,
            'resource_id' => $e->resourceId?->toRfc4122(),
            'result' => $e->outcome !== null ? $e->outcome->value : '',
            'failure_reason' => $e->failureReason,
            'created_at' => Time::format($e->timestamp),
            'ip_address' => $e->ipAddress,
            'user_agent' => $e->userAgent,
            'request_id' => $e->requestId->toRfc4122(),
            // Go maps must serialize as JSON objects even when empty.
            'metadata' => (object) $e->contextData,
            'critical' => $e->critical,
            'enrichment_metadata' => (object) $this->enrichmentMetadata,
            'enriched_at' => Time::format($this->enrichedAt),
            'written_at' => $this->writtenAt !== null ? Time::format($this->writtenAt) : null,
        ];
    }

    /**
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode($this, self::JSON_FLAGS);
    }

    /**
     * @throws \InvalidArgumentException|\JsonException on malformed input
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('audit event JSON must be an object');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \InvalidArgumentException on malformed input
     */
    public static function fromArray(array $data): self
    {
        $outcome = is_string($data['result'] ?? null) ? Outcome::tryFrom($data['result']) : null;

        $event = new AuditEvent(
            id: self::uuid($data, 'id') ?? throw new \InvalidArgumentException('audit event id is required'),
            action: self::string($data, 'action'),
            outcome: $outcome,
            timestamp: Time::parse(self::string($data, 'created_at')),
            requestId: self::uuid($data, 'request_id') ?? new NilUuid(),
            userId: self::uuid($data, 'user_id'),
            resourceType: self::string($data, 'resource_type'),
            resourceId: self::uuid($data, 'resource_id'),
            failureReason: self::string($data, 'failure_reason'),
            ipAddress: is_string($data['ip_address'] ?? null) ? $data['ip_address'] : null,
            userAgent: self::string($data, 'user_agent'),
            critical: ($data['critical'] ?? false) === true,
            contextData: self::map($data, 'metadata'),
        );

        $enrichedAt = self::string($data, 'enriched_at');
        $writtenAt = self::string($data, 'written_at');

        return new self(
            $event,
            self::map($data, 'enrichment_metadata'),
            $enrichedAt !== '' ? Time::parse($enrichedAt) : $event->timestamp,
            $writtenAt !== '' ? Time::parse($writtenAt) : null,
        );
    }

    public const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $data
     */
    private static function uuid(array $data, string $key): ?Uuid
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException(sprintf('audit event %s is not a valid UUID', $key));
        }

        return Uuid::fromString($value);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }
}
