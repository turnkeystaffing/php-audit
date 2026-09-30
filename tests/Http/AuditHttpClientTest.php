<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Turnkey\AuditClient\Config\HttpConfig;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Exception\DeliveryException;
use Turnkey\AuditClient\Tests\Support\Events;
use Turnkey\AuditClient\Tests\Support\FixedTokenProvider;
use Turnkey\AuditClient\Tests\Support\HttpScenario;
use Turnkey\AuditClient\Tests\Support\RecordingLogger;

final class AuditHttpClientTest extends TestCase
{
    public function testPostsBatchWithBearerToken(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());
        $events = Events::finals(2);

        $result = $http->auditClient()->send($events, HttpDeliveryProfile::batch());

        self::assertCount(1, $http->requests);
        $request = $http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://audit.example.com/api/v1/events/batch', $request['url']);
        self::assertContains('Authorization: Bearer test-token', $request['headers']);
        self::assertContains('Content-Type: application/json', $request['headers']);
        self::assertCount(2, $request['body']['events']);
        self::assertSame('getnative', $request['body']['events'][0]['service']);
        self::assertSame(0, $request['options']['max_redirects']);
        self::assertEquals(2.0, $request['options']['timeout']);
        self::assertTrue($result->isAccepted($events[0]->id()));
        self::assertTrue($result->isAccepted($events[1]->id()));
        self::assertFalse($result->hasRejections());
    }

    public function testEmptyBatchSendsNothing(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());

        $http->auditClient()->send([], HttpDeliveryProfile::batch());

        self::assertCount(0, $http->requests);
    }

    public function testParsesPerEventRejections(): void
    {
        $http = HttpScenario::of(HttpScenario::rejectIndexes([1 => false], 'unknown action'));
        $logger = new RecordingLogger();
        $events = Events::finals(3);

        $result = $http->auditClient(logger: $logger)->send($events, HttpDeliveryProfile::batch());

        self::assertTrue($result->isAccepted($events[0]->id()));
        self::assertFalse($result->isAccepted($events[1]->id()));
        self::assertTrue($result->isAccepted($events[2]->id()));
        self::assertSame('unknown action', $result->rejectionFor($events[1]->id())?->reason);
        self::assertFalse($result->rejectionFor($events[1]->id())?->retryable);
        self::assertTrue($logger->has('warning', 'rejected some events'));
    }

    public function testResolvesRejectedEventIdFromIndexWhenMissing(): void
    {
        $http = HttpScenario::of(new MockResponse(
            '{"accepted":0,"rejected":1,"event_ids":[],"errors":[{"index":0,"reason":"bad","retryable":true}]}',
            ['http_code' => 202],
        ));
        $events = Events::finals(1);

        $result = $http->auditClient()->send($events, HttpDeliveryProfile::batch());

        self::assertTrue($result->rejectionFor($events[0]->id())?->retryable);
    }

    public function testUnparseableAcceptedResponseCountsAsAccepted(): void
    {
        $http = HttpScenario::of(new MockResponse('not json', ['http_code' => 202]));
        $events = Events::finals(1);

        $result = $http->auditClient()->send($events, HttpDeliveryProfile::batch());

        self::assertNull($result->acceptedIds);
        self::assertTrue($result->isAccepted($events[0]->id()));
    }

    public function testRetriesServerErrorsThenSucceeds(): void
    {
        $http = HttpScenario::of(HttpScenario::status(503), HttpScenario::acceptAll());

        $http->auditClient()->send(Events::finals(1), HttpDeliveryProfile::batch());

        self::assertSame(2, $http->postCount());
        self::assertSame([100], $http->sleeps);
    }

    public function testRetriesNetworkErrors(): void
    {
        $http = HttpScenario::of(HttpScenario::networkError(), HttpScenario::networkError(), HttpScenario::acceptAll());

        $http->auditClient()->send(Events::finals(1), HttpDeliveryProfile::critical());

        self::assertSame(3, $http->postCount());
        self::assertSame([100, 200], $http->sleeps);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $http = HttpScenario::of(HttpScenario::status(500));

        try {
            $http->auditClient()->send(Events::finals(1), HttpDeliveryProfile::batch());
            self::fail('expected DeliveryException');
        } catch (DeliveryException $e) {
            self::assertTrue($e->retryable);
            self::assertSame(500, $e->httpStatus);
        }

        self::assertSame(2, $http->postCount());
    }

    public function testClientErrorIsNotRetried(): void
    {
        $http = HttpScenario::of(HttpScenario::status(400));

        try {
            $http->auditClient()->send(Events::finals(1), HttpDeliveryProfile::critical());
            self::fail('expected DeliveryException');
        } catch (DeliveryException $e) {
            self::assertFalse($e->retryable);
            self::assertSame(400, $e->httpStatus);
        }

        self::assertSame(1, $http->postCount());
    }

    public function testRedirectIsNotFollowedAndNotRetried(): void
    {
        $http = HttpScenario::of(HttpScenario::status(302, ['Location: https://evil.example.com']));

        try {
            $http->auditClient()->send(Events::finals(1), HttpDeliveryProfile::batch());
            self::fail('expected DeliveryException');
        } catch (DeliveryException $e) {
            self::assertFalse($e->retryable);
            self::assertSame(302, $e->httpStatus);
        }

        self::assertCount(1, $http->requests);
    }

    public function testTooManyRequestsHonoursCappedRetryAfter(): void
    {
        $http = HttpScenario::of(HttpScenario::status(429, ['Retry-After: 30']), HttpScenario::acceptAll());

        $http->auditClient()->send(Events::finals(1), HttpDeliveryProfile::batch());

        self::assertSame(2, $http->postCount());
        self::assertSame([1000], $http->sleeps);
    }

    public function testTokenIsFetchedOnEveryAttempt(): void
    {
        $http = HttpScenario::of(HttpScenario::status(503), HttpScenario::acceptAll());
        $tokens = new FixedTokenProvider(['first', 'second']);

        $http->auditClient($tokens)->send(Events::finals(1), HttpDeliveryProfile::batch());

        self::assertSame(2, $tokens->calls);
        self::assertContains('Authorization: Bearer first', $http->requests[0]['headers']);
        self::assertContains('Authorization: Bearer second', $http->requests[1]['headers']);
    }

    public function testTokenFailureIsPermanent(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());

        try {
            $http->auditClient(FixedTokenProvider::failing(new \RuntimeException('auth down')))
                ->send(Events::finals(1), HttpDeliveryProfile::critical());
            self::fail('expected DeliveryException');
        } catch (DeliveryException $e) {
            self::assertFalse($e->retryable);
            self::assertStringContainsString('auth down', $e->getMessage());
        }

        self::assertCount(0, $http->requests);
    }

    public function testCriticalProfileUsesLongerTimeout(): void
    {
        $http = HttpScenario::of(HttpScenario::acceptAll());

        $http->auditClient()->send(Events::finals(1), HttpDeliveryProfile::critical());

        self::assertEquals(3.0, $http->requests[0]['options']['timeout']);
    }

    public function testHealthChecksLiveness(): void
    {
        $http = HttpScenario::of(new MockResponse('ok', ['http_code' => 200]));

        $http->auditClient()->health();

        self::assertSame('GET', $http->requests[0]['method']);
        self::assertSame('https://audit.example.com/health/liveness', $http->requests[0]['url']);
    }

    public function testHealthFailsOnNon200(): void
    {
        $http = HttpScenario::of(HttpScenario::status(503));

        $this->expectException(DeliveryException::class);
        $http->auditClient()->health();
    }

    public function testWarnsAboutPlaintextBaseUrl(): void
    {
        $logger = new RecordingLogger();
        HttpScenario::of(HttpScenario::acceptAll())->auditClient(logger: $logger, baseUrl: 'http://audit.local');

        self::assertTrue($logger->has('warning', 'plaintext HTTP'));
    }

    public function testBearerTokenIsNeverLogged(): void
    {
        $logger = new RecordingLogger();
        $http = HttpScenario::of(HttpScenario::status(500));

        try {
            $http->auditClient(new FixedTokenProvider(['super-secret']), $logger)->send(Events::finals(1), HttpDeliveryProfile::batch());
        } catch (DeliveryException) {
        }

        self::assertStringNotContainsString('super-secret', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testConfigValidation(): void
    {
        foreach (['', 'ftp://audit.example.com', 'https://'] as $url) {
            try {
                new HttpConfig($url, 'svc');
                self::fail('expected InvalidArgumentException for ' . $url);
            } catch (\InvalidArgumentException) {
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new HttpConfig('https://audit.example.com', '');
    }

    public function testConfigTrimsTrailingSlash(): void
    {
        self::assertSame('https://audit.example.com/api/v1/events/batch', (new HttpConfig('https://audit.example.com/', 's'))->batchUrl());
    }
}
