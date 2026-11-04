<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Http;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Turnkey\AuditClient\Config\HttpConfig;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Exception\DeliveryException;
use Turnkey\AuditClient\Support\Backoff;
use Turnkey\AuthClient\TokenProviderInterface;

/**
 * Delivers events to the audit service:
 *  - a single event: POST {baseUrl}/api/v1/events with a bare EventDTO body. The service
 *    answers a duplicate event ID with 202 and that ID in event_ids, so a resend is accepted;
 *  - several events: POST {baseUrl}/api/v1/events/batch with {"events": [...]}. The batch
 *    endpoint omits duplicates from both event_ids and errors.
 *
 * Port of Go HTTPWriter.WriteBatch/doRequest:
 *  - a fresh bearer token is fetched on every attempt (picks up refreshed tokens between retries);
 *  - network errors, 5xx and 429 are retried with exponential backoff (429 honors Retry-After, capped);
 *  - any other non-202 status fails immediately, except a single-event 400 carrying per-event
 *    errors, which is returned as a rejection with the service's reason;
 *  - redirects are never followed (prevents token leakage / SSRF);
 *  - 202 responses are parsed into a DeliveryResult with per-event rejections.
 */
final readonly class AuditHttpClient
{
    private const int MAX_RESPONSE_BYTES = 1_048_576;
    private const int MAX_BACKOFF_MS = 5000;
    private const float HEALTH_TIMEOUT_SECONDS = 5.0;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep sleeps for the given milliseconds (injectable for tests)
     */
    public function __construct(
        private HttpConfig $config,
        private TokenProviderInterface $tokenProvider,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        \Closure|null $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };

        if ($config->isPlaintext()) {
            $logger->warning('audit http: base URL uses plaintext HTTP — bearer token will be transmitted without TLS', [
                'base_url' => $config->baseUrl,
            ]);
        }
    }

    public function serviceName(): string
    {
        return $this->config->serviceName;
    }

    /**
     * @param list<FinalAuditEvent> $events
     *
     * @throws DeliveryException when the batch as a whole was not accepted
     */
    public function send(array $events, HttpDeliveryProfile $profile): DeliveryResult
    {
        if ($events === []) {
            return new DeliveryResult([], []);
        }

        $single = count($events) === 1;
        $url = $single ? $this->config->eventUrl() : $this->config->batchUrl();

        try {
            $body = json_encode(
                $single
                    ? EventDtoMapper::toDto($events[0], $this->config->serviceName)
                    : EventDtoMapper::batchRequest($events, $this->config->serviceName),
                FinalAuditEvent::JSON_FLAGS,
            );
        } catch (\JsonException $e) {
            throw DeliveryException::encoding($e);
        }

        $eventIds = array_map(static fn (FinalAuditEvent $e) => $e->id(), $events);

        $lastError = null;
        for ($attempt = 0; $attempt <= $profile->maxRetries; $attempt++) {
            if ($lastError !== null) {
                $delay = $this->retryDelay($lastError, $profile, $attempt - 1);
                $this->logger->warning('audit http: retrying after transient failure', [
                    'attempt' => $attempt + 1,
                    'max_attempts' => $profile->maxRetries + 1,
                    'retry_delay_ms' => $delay,
                    'events' => count($events),
                    'error' => $lastError->getMessage(),
                ]);
                ($this->sleep)($delay);
            }

            try {
                $token = $this->tokenProvider->getToken();
            } catch (\Throwable $e) {
                throw DeliveryException::tokenUnavailable($e);
            }

            try {
                return $this->doRequest($url, $body, $token, $eventIds, $single, $profile);
            } catch (DeliveryException $e) {
                if (!$e->retryable) {
                    throw $e;
                }
                $lastError = $e;
            }
        }

        $this->logger->error('audit http: delivery failed after retries', [
            'attempts' => $profile->maxRetries + 1,
            'events' => count($events),
            'error' => $lastError?->getMessage(),
        ]);

        throw $lastError ?? DeliveryException::status(0, true);
    }

    /**
     * Liveness probe: GET {baseUrl}/health/liveness must return 200.
     *
     * @throws DeliveryException
     */
    public function health(): void
    {
        try {
            $response = $this->httpClient->request('GET', $this->config->healthUrl(), [
                'timeout' => self::HEALTH_TIMEOUT_SECONDS,
                'max_duration' => self::HEALTH_TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw DeliveryException::network($e);
        }

        if ($status !== 200) {
            throw DeliveryException::status($status, true);
        }
    }

    /**
     * @param list<string> $eventIds
     * @param bool         $single   the request went to the single-event endpoint
     *
     * @throws DeliveryException
     */
    private function doRequest(
        string $url,
        string $body,
        string $token,
        array $eventIds,
        bool $single,
        HttpDeliveryProfile $profile,
    ): DeliveryResult {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body' => $body,
                'timeout' => $profile->timeoutSeconds,
                'max_duration' => $profile->timeoutSeconds,
                'max_redirects' => 0,
            ]);

            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface $e) {
            throw DeliveryException::network($e);
        }

        if ($status === 202) {
            $data = $this->decodeResponse($content);

            return $data !== null ? $this->toResult($data, $eventIds) : new DeliveryResult(null, []);
        }

        // The single-event endpoint reports a validation failure as 400 with an IngestionResponse body.
        if ($status === 400 && $single) {
            $data = $this->decodeResponse($content);
            if ($data !== null && is_array($data['errors'] ?? null) && $data['errors'] !== []) {
                return $this->toResult($data, $eventIds);
            }
        }

        if ($status === 429) {
            $retryAfter = $headers['retry-after'][0] ?? '';
            $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : 0;

            throw DeliveryException::status($status, true, $seconds > 0 ? $seconds * 1000 : null);
        }

        // 5xx is transient; 3xx (redirects are not followed) and other 4xx are permanent.
        throw DeliveryException::status($status, $status >= 500);
    }

    /**
     * @return array<mixed>|null null when the body is empty, oversized or not a JSON object
     */
    private function decodeResponse(string $content): array|null
    {
        if ($content === '' || strlen($content) > self::MAX_RESPONSE_BYTES) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<mixed> $data     decoded IngestionResponse
     * @param list<string> $eventIds
     */
    private function toResult(array $data, array $eventIds): DeliveryResult
    {
        $acceptedIds = null;
        if (is_array($data['event_ids'] ?? null)) {
            $acceptedIds = array_values(array_filter($data['event_ids'], 'is_string'));
        }

        $rejected = [];
        foreach (is_array($data['errors'] ?? null) ? $data['errors'] : [] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $index = is_int($error['index'] ?? null) ? $error['index'] : -1;
            $eventId = is_string($error['event_id'] ?? null) && $error['event_id'] !== ''
                ? $error['event_id']
                : ($eventIds[$index] ?? '');
            $rejected[] = new RejectedEvent(
                $index,
                $eventId,
                is_string($error['reason'] ?? null) ? $error['reason'] : 'rejected',
                ($error['retryable'] ?? false) === true,
            );
        }

        if ($rejected !== []) {
            $context = [
                'accepted' => is_int($data['accepted'] ?? null) ? $data['accepted'] : null,
                'rejected' => count($rejected),
                'total_sent' => count($eventIds),
            ];
            foreach ($rejected as $i => $r) {
                $context["errors.$i.reason"] = $r->reason;
                $context["errors.$i.event_id"] = $r->eventId;
                $context["errors.$i.retryable"] = $r->retryable;
            }
            $this->logger->warning('audit http: audit service rejected some events', $context);
        }

        return new DeliveryResult($acceptedIds, $rejected);
    }

    private function retryDelay(DeliveryException $lastError, HttpDeliveryProfile $profile, int $attempt): int
    {
        if ($lastError->retryAfterMs !== null && $lastError->retryAfterMs > 0) {
            return min($lastError->retryAfterMs, $profile->maxRetryAfterMs);
        }

        return Backoff::delay($profile->retryBaseDelayMs, self::MAX_BACKOFF_MS, $attempt);
    }
}
