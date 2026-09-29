<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Event;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Turnkey\AuditClient\Event\EventBuilder;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Event\Outcome;
use Turnkey\AuditClient\Support\Time;

/**
 * Contract tests: the queue/file format must stay compatible with Go json.Marshal(FinalAuditEvent).
 * Fixtures follow the Go struct tags of go-audit event.go.
 */
final class FinalAuditEventTest extends TestCase
{
    private const array GO_KEYS = [
        'id', 'user_id', 'action', 'resource_type', 'resource_id', 'result', 'failure_reason', 'created_at',
        'ip_address', 'user_agent', 'request_id', 'metadata', 'critical', 'enrichment_metadata', 'enriched_at', 'written_at',
    ];

    public function testSerializesWithGoFieldNames(): void
    {
        $json = $this->sampleEvent()->toJson();
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(self::GO_KEYS, array_keys($decoded));
        self::assertSame('success', $decoded['result']);
        self::assertSame('10.0.0.1', $decoded['ip_address']);
        self::assertSame(['email' => 'user@example.com'], $decoded['metadata']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $decoded['created_at']);
        self::assertNull($decoded['written_at']);
    }

    public function testEmptyMapsSerializeAsJsonObjects(): void
    {
        $event = (new EventBuilder())->withAction('x')->withSuccess()->build();
        $json = (new FinalAuditEvent($event, [], Time::now()))->toJson();

        self::assertStringContainsString('"metadata":{}', $json);
        self::assertStringContainsString('"enrichment_metadata":{}', $json);
        self::assertStringContainsString('"user_id":null', $json);
        self::assertStringContainsString('"resource_id":null', $json);
        self::assertStringContainsString('"ip_address":null', $json);
    }

    public function testListLikeMetadataStillSerializesAsObject(): void
    {
        $event = (new EventBuilder())->withAction('x')->withSuccess()->withContext(['a', 'b'])->build();
        $json = (new FinalAuditEvent($event, [], Time::now()))->toJson();

        self::assertStringContainsString('"metadata":{"0":"a","1":"b"}', $json);
    }

    public function testParsesGoProducedJson(): void
    {
        $final = FinalAuditEvent::fromJson((string) file_get_contents(__DIR__ . '/../Fixtures/go_final_event.json'));
        $e = $final->event;

        self::assertSame('0192f3a1-7c2e-7b4a-9d3e-1a2b3c4d5e6f', $e->id->toRfc4122());
        self::assertSame('0192f3a1-0000-7000-8000-000000000001', $e->userId?->toRfc4122());
        self::assertSame('login_success', $e->action);
        self::assertSame('session', $e->resourceType);
        self::assertSame(Outcome::Success, $e->outcome);
        self::assertSame('10.0.0.1', $e->ipAddress);
        self::assertSame(['email' => 'user@example.com', 'attempt' => 2], $e->contextData);
        self::assertSame(['enrichment.client_id' => 'web'], $final->enrichmentMetadata);
        // Go nanoseconds are truncated to microseconds.
        self::assertSame('2026-09-29T10:00:00.123456Z', Time::format($e->timestamp));
        self::assertSame('2026-09-29T10:00:00.200000Z', Time::format($final->enrichedAt));
        self::assertNull($final->writtenAt);
    }

    public function testParsesMinimalGoEvent(): void
    {
        $final = FinalAuditEvent::fromJson((string) file_get_contents(__DIR__ . '/../Fixtures/go_final_event_minimal.json'));
        $e = $final->event;

        self::assertNull($e->userId);
        self::assertNull($e->resourceId);
        self::assertNull($e->ipAddress);
        self::assertSame(Outcome::Failure, $e->outcome);
        self::assertSame('invalid password', $e->failureReason);
        self::assertTrue($e->critical);
        self::assertSame([], $e->contextData);
    }

    public function testRoundTripPreservesEverything(): void
    {
        $original = $this->sampleEvent();
        $restored = FinalAuditEvent::fromJson($original->toJson());

        self::assertSame(
            json_decode($original->toJson(), true),
            json_decode($restored->toJson(), true),
        );
    }

    public function testGoFixtureRoundTripsToSameStructure(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/go_final_event.json'), true);
        $reencoded = json_decode(FinalAuditEvent::fromArray($fixture)->toJson(), true);

        self::assertSame(array_keys($fixture), array_keys($reencoded));
        foreach (['id', 'user_id', 'action', 'resource_type', 'resource_id', 'result', 'ip_address', 'request_id', 'metadata', 'critical', 'enrichment_metadata'] as $key) {
            self::assertSame($fixture[$key], $reencoded[$key], $key);
        }
    }

    public function testRejectsInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FinalAuditEvent::fromArray(['id' => 'not-a-uuid', 'created_at' => '2026-09-29T10:00:00Z']);
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(\JsonException::class);
        FinalAuditEvent::fromJson('{broken');
    }

    private function sampleEvent(): FinalAuditEvent
    {
        $event = (new EventBuilder())
            ->withUser(Uuid::v7())
            ->withAction('login_success')
            ->withResource('session', Uuid::v7())
            ->withSuccess()
            ->withHttpContext('10.0.0.1', 'Mozilla/5.0')
            ->withContext(['email' => 'user@example.com'])
            ->build();

        return new FinalAuditEvent($event, ['enrichment.client_id' => 'web'], Time::now());
    }
}
