<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Turnkey\AuditClient\Config\HttpConfig;
use Turnkey\AuditClient\Http\AuditHttpClient;

/**
 * Scripted audit service: responses are served in order (the last one repeats) and requests are recorded.
 */
final class HttpScenario
{
    public const string BASE_URL = 'https://audit.example.com';

    /** @var list<array{method: string, url: string, body: array<mixed>|null, headers: list<string>, options: array<mixed>}> */
    public array $requests = [];

    /** @var list<int> */
    public array $sleeps = [];

    /** @var list<MockResponse|\Closure(array<mixed>): MockResponse> */
    private array $responses;

    public readonly MockHttpClient $client;

    /** @param list<MockResponse|\Closure(array<mixed>): MockResponse> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = isset($options['body']) && is_string($options['body']) && $options['body'] !== ''
                ? json_decode($options['body'], true)
                : null;
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => is_array($body) ? $body : null,
                'headers' => $options['headers'] ?? [],
                'options' => $options,
            ];

            $next = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];

            return $next instanceof \Closure ? $next($body ?? []) : clone $next;
        });
    }

    /** @param list<MockResponse|\Closure(array<mixed>): MockResponse> $responses */
    public static function of(MockResponse|\Closure ...$responses): self
    {
        return new self(array_values($responses));
    }

    /** 202 accepting every submitted event. */
    public static function acceptAll(): \Closure
    {
        return static function (array $body): MockResponse {
            $ids = array_map(static fn (array $e) => $e['event_id'], self::eventsOf($body));

            return new MockResponse(
                json_encode(['accepted' => count($ids), 'rejected' => 0, 'event_ids' => $ids, 'errors' => []], JSON_THROW_ON_ERROR),
                ['http_code' => 202],
            );
        };
    }

    /**
     * 202 rejecting the events at the given indexes.
     *
     * @param array<int, bool> $rejectIndexes index => retryable
     */
    public static function rejectIndexes(array $rejectIndexes, string $reason = 'invalid event'): \Closure
    {
        return static function (array $body) use ($rejectIndexes, $reason): MockResponse {
            $accepted = [];
            $errors = [];
            foreach (self::eventsOf($body) as $i => $event) {
                if (array_key_exists($i, $rejectIndexes)) {
                    $errors[] = ['index' => $i, 'event_id' => $event['event_id'], 'reason' => $reason, 'retryable' => $rejectIndexes[$i]];
                } else {
                    $accepted[] = $event['event_id'];
                }
            }

            return new MockResponse(json_encode([
                'accepted' => count($accepted),
                'rejected' => count($errors),
                'event_ids' => $accepted,
                'errors' => $errors,
            ], JSON_THROW_ON_ERROR), ['http_code' => 202]);
        };
    }

    /** 400 from the single-event endpoint: the event failed validation. */
    public static function singleRejected(string $reason = 'invalid event', bool $retryable = false): \Closure
    {
        return static fn (array $body): MockResponse => new MockResponse(json_encode([
            'accepted' => 0,
            'rejected' => 1,
            'event_ids' => [],
            'errors' => [['index' => 0, 'event_id' => $body['event_id'] ?? '', 'reason' => $reason, 'retryable' => $retryable]],
        ], JSON_THROW_ON_ERROR), ['http_code' => 400]);
    }

    public static function status(int $code, array $headers = []): MockResponse
    {
        return new MockResponse('{}', ['http_code' => $code, 'response_headers' => $headers]);
    }

    /**
     * Event IDs sent in a recorded request, for both the batch and the single-event endpoint.
     *
     * @param array{body: array<mixed>|null} $request
     *
     * @return list<string>
     */
    public static function eventIdsOf(array $request): array
    {
        return array_column(self::eventsOf($request['body'] ?? []), 'event_id');
    }

    /**
     * Events of a request body: the batch "events" list, or the bare single-event DTO.
     *
     * @param array<mixed> $body
     *
     * @return list<array<mixed>>
     */
    private static function eventsOf(array $body): array
    {
        if (isset($body['events']) && is_array($body['events'])) {
            return array_values($body['events']);
        }

        return isset($body['event_id']) ? [$body] : [];
    }

    public static function networkError(): MockResponse
    {
        return new MockResponse('', ['error' => 'Could not resolve host']);
    }

    public function auditClient(FixedTokenProvider|null $tokens = null, RecordingLogger|null $logger = null, string $baseUrl = self::BASE_URL): AuditHttpClient
    {
        return new AuditHttpClient(
            new HttpConfig($baseUrl, 'getnative'),
            $tokens ?? new FixedTokenProvider(),
            $this->client,
            $logger ?? new RecordingLogger(),
            function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
    }

    public function postCount(): int
    {
        return count(array_filter($this->requests, static fn (array $r) => $r['method'] === 'POST'));
    }
}
