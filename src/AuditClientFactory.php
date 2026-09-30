<?php

declare(strict_types=1);

namespace Turnkey\AuditClient;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Turnkey\AuditClient\Config\AuditClientConfig;
use Turnkey\AuditClient\Consumer\RedisQueueConsumer;
use Turnkey\AuditClient\Enricher\EnricherInterface;
use Turnkey\AuditClient\Http\AuditHttpClient;
use Turnkey\AuditClient\Redis\AuditRedisClientInterface;
use Turnkey\AuditClient\Replay\FileReplayer;
use Turnkey\AuditClient\Writer\FileWriter;
use Turnkey\AuditClient\Writer\HttpWriter;
use Turnkey\AuditClient\Writer\RedisQueueWriter;
use Turnkey\AuthClient\TokenProviderInterface;

/**
 * Wires the client (port of Go NewProducerRouter).
 *
 *  - createLogger():        non-critical chain HTTP -> Redis (when a client is given) -> File,
 *                           critical events over HTTP only
 *  - createQueueConsumer(): drains the Redis queue (audit:consume-queue)
 *  - createFileReplayer():  delivers JSONL files (audit:replay)
 */
final class AuditClientFactory
{
    private ?AuditHttpClient $httpClient = null;

    public function __construct(
        private readonly AuditClientConfig $config,
        private readonly TokenProviderInterface $tokenProvider,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly ?AuditRedisClientInterface $redis = null,
    ) {
    }

    /**
     * @param iterable<EnricherInterface> $enrichers
     */
    public function createLogger(iterable $enrichers = []): AuditLoggerInterface
    {
        if (!$this->config->audit->enabled) {
            return new NoopAuditLogger();
        }

        $client = $this->httpClient();
        $writers = [new HttpWriter($client, $this->config->batchProfile, $this->logger)];
        if ($this->redis !== null) {
            $writers[] = new RedisQueueWriter($this->redis, $this->config->redisQueue, $this->logger);
        }
        $writers[] = new FileWriter($this->config->file, $this->logger);

        return new AuditRouter(
            $this->config->audit,
            $client,
            $this->config->criticalProfile,
            $writers,
            $this->logger,
            $enrichers,
        );
    }

    public function createQueueConsumer(): RedisQueueConsumer
    {
        if ($this->redis === null) {
            throw new \LogicException('audit: queue consumer requires a Redis client');
        }

        return new RedisQueueConsumer(
            $this->redis,
            $this->httpClient(),
            $this->config->batchProfile,
            $this->config->redisQueue,
            $this->config->consumer,
            $this->logger,
        );
    }

    public function createFileReplayer(): FileReplayer
    {
        return new FileReplayer(
            $this->config->file,
            $this->httpClient(),
            $this->config->batchProfile,
            $this->config->replay,
            $this->logger,
        );
    }

    private function httpClient(): AuditHttpClient
    {
        return $this->httpClient ??= new AuditHttpClient($this->config->http, $this->tokenProvider, $this->http, $this->logger);
    }
}
