<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Event;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Turnkey\AuditClient\Event\AuditEvent;
use Turnkey\AuditClient\Event\EventBuilder;
use Turnkey\AuditClient\Event\Outcome;
use Turnkey\AuditClient\Exception\InvalidAuditEventException;

final class AuditEventTest extends TestCase
{
    public function testBuilderSetsAllFields(): void
    {
        $userId = Uuid::v7();
        $resourceId = Uuid::v7();
        $requestId = Uuid::v7();

        $event = (new EventBuilder())
            ->withUser($userId)
            ->withAction('login_success')
            ->withResource('session', $resourceId)
            ->withSuccess()
            ->withHttpContext('192.168.1.10', 'Mozilla/5.0')
            ->withRequestId($requestId)
            ->withContext(['email' => 'user@example.com'])
            ->withContextValue('attempt', 1)
            ->withCritical()
            ->build();

        self::assertInstanceOf(UuidV7::class, $event->id);
        self::assertSame($userId, $event->userId);
        self::assertSame('login_success', $event->action);
        self::assertSame('session', $event->resourceType);
        self::assertSame($resourceId, $event->resourceId);
        self::assertSame(Outcome::Success, $event->outcome);
        self::assertSame('192.168.1.10', $event->ipAddress);
        self::assertSame('Mozilla/5.0', $event->userAgent);
        self::assertSame($requestId, $event->requestId);
        self::assertSame(['email' => 'user@example.com', 'attempt' => 1], $event->contextData);
        self::assertTrue($event->critical);
        self::assertSame('UTC', $event->timestamp->getTimezone()->getName());
    }

    public function testBuilderDefaults(): void
    {
        $event = (new EventBuilder())->withAction('x')->withSuccess()->build();

        self::assertNull($event->userId);
        self::assertNull($event->ipAddress);
        self::assertSame([], $event->contextData);
        self::assertFalse($event->critical);
        self::assertInstanceOf(UuidV7::class, $event->requestId);
    }

    public function testEmptyIpIsTreatedAsMissing(): void
    {
        $event = (new EventBuilder())->withAction('x')->withSuccess()->withHttpContext('', 'ua')->build();

        self::assertNull($event->ipAddress);
    }

    public function testFailureAndPartialCarryReason(): void
    {
        $failure = (new EventBuilder())->withAction('x')->withFailure('bad password')->build();
        $partial = (new EventBuilder())->withAction('x')->withPartial('smtp retry')->build();

        self::assertSame(Outcome::Failure, $failure->outcome);
        self::assertSame('bad password', $failure->failureReason);
        self::assertSame(Outcome::Partial, $partial->outcome);
        self::assertSame('smtp retry', $partial->failureReason);
    }

    public function testValidEventPassesValidation(): void
    {
        $this->expectNotToPerformAssertions();
        (new EventBuilder())->withAction('login')->withSuccess()->withHttpContext('::1', '')->build()->validate();
    }

    public function testValidationRequiresAction(): void
    {
        $this->expectException(InvalidAuditEventException::class);
        $this->expectExceptionMessage('action is required');
        (new EventBuilder())->withSuccess()->build()->validate();
    }

    public function testValidationRequiresOutcome(): void
    {
        $this->expectException(InvalidAuditEventException::class);
        $this->expectExceptionMessage('outcome');
        (new EventBuilder())->withAction('login')->build()->validate();
    }

    public function testValidationRejectsMalformedIp(): void
    {
        $this->expectException(InvalidAuditEventException::class);
        $this->expectExceptionMessage('invalid IP address');
        (new EventBuilder())->withAction('login')->withSuccess()->withHttpContext('999.1.1.1', '')->build()->validate();
    }

    public function testValidationRequiresRequestId(): void
    {
        $this->expectException(InvalidAuditEventException::class);
        $this->expectExceptionMessage('request ID');
        (new EventBuilder())->withAction('login')->withSuccess()->withRequestId(new NilUuid())->build()->validate();
    }

    public function testAddContextNeverOverwrites(): void
    {
        $event = (new EventBuilder())->withAction('x')->withSuccess()->withContextValue('email', 'a@b.c')->build();

        self::assertFalse($event->addContext('email', 'evil@b.c'));
        self::assertTrue($event->addContext('client_id', 'web'));
        self::assertSame(['email' => 'a@b.c', 'client_id' => 'web'], $event->contextData);
    }

    public function testCoreFieldsAreImmutable(): void
    {
        $event = (new EventBuilder())->withAction('x')->withSuccess()->build();

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $event->action = 'tampered';
    }

    public function testContextCannotBeReplacedFromOutside(): void
    {
        $event = (new EventBuilder())->withAction('x')->withSuccess()->build();

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $event->contextData = ['x' => 1];
    }

    public function testWithContextDataReturnsCopy(): void
    {
        $event = (new EventBuilder())->withAction('x')->withSuccess()->withContextValue('a', 1)->build();
        $copy = $event->withContextData(['b' => 2]);

        self::assertInstanceOf(AuditEvent::class, $copy);
        self::assertSame(['a' => 1], $event->contextData);
        self::assertSame(['b' => 2], $copy->contextData);
        self::assertSame($event->id, $copy->id);
    }
}
