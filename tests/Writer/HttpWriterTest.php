<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Writer;

use PHPUnit\Framework\TestCase;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Exception\WriteFailedException;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\HttpScenario;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;
use Turnkey\AuditClient\Writer\HttpWriter;

final class HttpWriterTest extends TestCase
{
    public function testAcceptedBatchLeavesNothingForNextTier(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());
        $writer = new HttpWriter($http->auditClient(), HttpDeliveryProfile::batch(), new RecordingLogger());

        self::assertSame([], $writer->writeBatch(Events::finals(3)));
        self::assertSame('http', $writer->name());
    }

    public function testFailedDeliveryThrowsSoRouterFallsBack(): void
    {
        $http = HttpScenario::of(HttpScenario::status(503));
        $writer = new HttpWriter($http->auditClient(), HttpDeliveryProfile::batch(), new RecordingLogger());

        $this->expectException(WriteFailedException::class);
        $writer->writeBatch(Events::finals(1));
    }

    public function testRetryableRejectionsAreForwardedAndPermanentOnesDropped(): void
    {
        $http = HttpScenario::of(HttpScenario::rejectIndexes([0 => true, 2 => false]));
        $logger = new RecordingLogger();
        $writer = new HttpWriter($http->auditClient(logger: $logger), HttpDeliveryProfile::batch(), $logger);
        $events = Events::finals(3);

        $forward = $writer->writeBatch($events);

        self::assertSame([$events[0]], $forward);
        self::assertTrue($logger->has('error', 'rejected by audit service, dropping'));
        self::assertSame($events[2]->id(), $logger->contextOf('rejected by audit service, dropping')['event_id']);
    }
}
