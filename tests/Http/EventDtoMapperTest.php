<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Http;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Http\EventDtoMapper;

/**
 * Wire-format contract with the audit service (internal/audit/gateway/dto/ingestion.go).
 */
final class EventDtoMapperTest extends TestCase
{
    public function testMatchesGoWireFormat(): void
    {
        $events = [
            FinalAuditEvent::fromJson((string) file_get_contents(__DIR__ . '/../Fixtures/go_final_event.json')),
            FinalAuditEvent::fromJson((string) file_get_contents(__DIR__ . '/../Fixtures/go_final_event_minimal.json')),
        ];

        $json = json_encode(EventDtoMapper::batchRequest($events, 'getnative'), FinalAuditEvent::JSON_FLAGS);

        self::assertJsonStringEqualsJsonString(
            (string) file_get_contents(__DIR__ . '/../Fixtures/http_batch_request.json'),
            $json,
        );
    }

    public function testOmitsEmptyOptionalFields(): void
    {
        $event = FinalAuditEvent::fromJson((string) file_get_contents(__DIR__ . '/../Fixtures/go_final_event_minimal.json'));
        $dto = EventDtoMapper::toDto($event, 'svc');

        self::assertArrayNotHasKey('metadata', $dto);
        self::assertArrayNotHasKey('id', $dto['resource']);
        self::assertSame('', $dto['actor']);
        self::assertArrayNotHasKey('ip_address', $dto);
        self::assertArrayNotHasKey('request_id', $dto);
    }
}
