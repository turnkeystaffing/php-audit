<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Config;

/**
 * Complete client configuration consumed by AuditClientFactory.
 */
final readonly class AuditClientConfig
{
    public HttpDeliveryProfile $batchProfile;
    public HttpDeliveryProfile $criticalProfile;
    public AuditConfig $audit;
    public RedisQueueConfig $redisQueue;
    public QueueConsumerConfig $consumer;
    public FileReplayConfig $replay;

    public function __construct(
        public HttpConfig $http,
        public FileConfig $file,
        ?AuditConfig $audit = null,
        ?HttpDeliveryProfile $batchProfile = null,
        ?HttpDeliveryProfile $criticalProfile = null,
        ?RedisQueueConfig $redisQueue = null,
        ?QueueConsumerConfig $consumer = null,
        ?FileReplayConfig $replay = null,
    ) {
        $this->audit = $audit ?? new AuditConfig();
        $this->batchProfile = $batchProfile ?? HttpDeliveryProfile::batch();
        $this->criticalProfile = $criticalProfile ?? HttpDeliveryProfile::critical();
        $this->redisQueue = $redisQueue ?? new RedisQueueConfig();
        $this->consumer = $consumer ?? new QueueConsumerConfig();
        $this->replay = $replay ?? new FileReplayConfig();
    }

    /**
     * Builds the configuration from a plain array (e.g. Symfony parameters / env).
     *
     *     AuditClientConfig::fromArray([
     *         'base_url' => 'https://audit.example.com',   // required
     *         'service_name' => 'getnative',               // required
     *         'file_directory' => '/var/lib/app/audit',    // required
     *         'enabled' => true,
     *         'batch_size' => 100,
     *         'file_rotation' => 'daily',
     *         'http_timeout' => 2.0, 'http_max_retries' => 1,
     *         'critical_timeout' => 3.0, 'critical_max_retries' => 2,
     *         'queue_key' => 'audit:fallback:queue', 'queue_max_size' => 10000, 'queue_ttl' => 604800,
     *         'consumer_batch_size' => 100, 'replay_batch_size' => 100,
     *     ]);
     *
     * @param array<string, mixed> $c
     */
    public static function fromArray(array $c): self
    {
        $str = static fn (string $key, string $default = ''): string => isset($c[$key]) ? (string) $c[$key] : $default;
        $int = static fn (string $key, int $default): int => isset($c[$key]) ? (int) $c[$key] : $default;
        $float = static fn (string $key, float $default): float => isset($c[$key]) ? (float) $c[$key] : $default;
        $bool = static fn (string $key, bool $default): bool => isset($c[$key])
            ? filter_var($c[$key], FILTER_VALIDATE_BOOLEAN)
            : $default;

        return new self(
            http: new HttpConfig($str('base_url'), $str('service_name')),
            file: new FileConfig($str('file_directory'), $str('file_rotation', FileConfig::ROTATION_DAILY)),
            audit: new AuditConfig(
                enabled: $bool('enabled', true),
                batchSize: $int('batch_size', 100),
                enricherBudgetMs: $int('enricher_budget_ms', 100),
            ),
            batchProfile: new HttpDeliveryProfile(
                timeoutSeconds: $float('http_timeout', 2.0),
                maxRetries: $int('http_max_retries', 1),
                retryBaseDelayMs: $int('http_retry_base_delay_ms', 100),
                maxRetryAfterMs: $int('http_max_retry_after_ms', 1000),
            ),
            criticalProfile: new HttpDeliveryProfile(
                timeoutSeconds: $float('critical_timeout', 3.0),
                maxRetries: $int('critical_max_retries', 2),
                retryBaseDelayMs: $int('critical_retry_base_delay_ms', 100),
                maxRetryAfterMs: $int('critical_max_retry_after_ms', 1000),
            ),
            redisQueue: new RedisQueueConfig(
                queueKey: $str('queue_key', RedisQueueConfig::DEFAULT_QUEUE_KEY),
                maxQueueSize: $int('queue_max_size', 10000),
                queueTtlSeconds: $int('queue_ttl', RedisQueueConfig::DEFAULT_TTL_SECONDS),
            ),
            consumer: new QueueConsumerConfig(batchSize: $int('consumer_batch_size', 100)),
            replay: new FileReplayConfig(
                batchSize: $int('replay_batch_size', 100),
                minFileAgeSeconds: $int('replay_min_file_age', 60),
            ),
        );
    }
}
