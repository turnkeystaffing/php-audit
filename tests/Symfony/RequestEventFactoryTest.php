<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Turnkey\AuditClient\Symfony\RequestEventFactory;

final class RequestEventFactoryTest extends TestCase
{
    public function testPrefillsFromCurrentRequest(): void
    {
        $requestId = Uuid::v7();
        $userId = Uuid::v7();
        $request = Request::create('/login', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'HTTP_X_REQUEST_ID' => $requestId->toRfc4122(),
        ]);
        $request->attributes->set(RequestEventFactory::USER_ID_ATTRIBUTE, $userId->toRfc4122());

        $event = $this->factory($request)->builder()->withAction('login')->withSuccess()->build();

        self::assertSame('203.0.113.7', $event->ipAddress);
        self::assertSame('Mozilla/5.0', $event->userAgent);
        self::assertTrue($requestId->equals($event->requestId));
        self::assertTrue($userId->equals($event->userId));
    }

    public function testIgnoresNonUuidRequestId(): void
    {
        $request = Request::create('/', server: ['HTTP_X_REQUEST_ID' => 'abc-123']);

        $event = $this->factory($request)->builder()->withAction('x')->withSuccess()->build();

        self::assertNotSame('abc-123', $event->requestId->toRfc4122());
    }

    public function testAcceptsUuidObjectAsUserId(): void
    {
        $userId = Uuid::v7();
        $request = Request::create('/');
        $request->attributes->set(RequestEventFactory::USER_ID_ATTRIBUTE, $userId);

        $event = $this->factory($request)->builder()->withAction('x')->withSuccess()->build();

        self::assertSame($userId, $event->userId);
    }

    public function testWorksOutsideOfRequest(): void
    {
        $event = (new RequestEventFactory(new RequestStack()))->builder()->withAction('cron')->withSuccess()->build();

        self::assertNull($event->ipAddress);
        self::assertNull($event->userId);
    }

    private function factory(Request $request): RequestEventFactory
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new RequestEventFactory($stack);
    }
}
