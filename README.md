# php-audit

Audit client library for PHP 8.4+ / Symfony 7. Port of [go-audit](../go-audit) (producer side).

Sends security audit events to the central Audit Service:

- **Critical events** (compliance) are delivered **synchronously over HTTP only**. If the audit service does not accept the event, `CriticalAuditException` is thrown and the originating operation must be rejected. There is no fallback.
- **Regular events** are buffered for the duration of the request and delivered after the response (`kernel.terminate`) through a fallback chain **HTTP → Redis queue → JSONL file**. Two background commands deliver what ended up in Redis (`audit:consume-queue`) and in files (`audit:replay`).

## Installation

```bash
composer require turnkey/auditclient
composer require predis/predis   # for the Redis fallback tier and audit:consume-queue
```

`turnkey/auditclient` depends on `turnkey/authclient` (bearer tokens), resolved from `git@github.com:turnkeystaffing/php-authclient.git`. Redis 6.2+ is required (`RPOP key count`).

## Delivery guarantees

| | Critical (`withCritical()`) | Regular |
|---|---|---|
| When | synchronously inside `log()` | after the response (`kernel.terminate`), or immediately once `batch_size` events are buffered |
| Channels | HTTP only | HTTP → Redis → File |
| HTTP profile (default) | 3 s timeout, 2 retries | 2 s timeout, 1 retry |
| On failure | `CriticalAuditException` | logged; events go to the next tier, or are logged as lost if every tier fails |
| 202 with the event in `errors` | not delivered → exception | `retryable=true` → next tier; `retryable=false` → dropped with an error log |

Retries apply to network errors, 5xx and 429 (`Retry-After` honoured, capped at 1 s). Other 4xx, 3xx (redirects are never followed) and token failures fail immediately.

## Components

| Component | Purpose |
|---|---|
| `EventBuilder`, `AuditEvent`, `Outcome` | Event model. Core fields are `readonly`; enrichers may only add context keys |
| `AuditLoggerInterface` / `AuditRouter` | `log()`, `logCritical()`, `flush()`: request buffer, enrichment, fallback chain, critical path |
| `NoopAuditLogger` | Used when auditing is disabled |
| `Http\AuditHttpClient` | `POST /api/v1/events/batch` with bearer token, retries, per-event rejection parsing, `GET /health/liveness` |
| `Writer\HttpWriter`, `RedisQueueWriter`, `FileWriter` | Tiers of the regular-event chain |
| `Redis\PrefixedRedisClient` | Key-prefixing wrapper around `Predis\Client` (same pattern as authclient's `PrefixedClient`) |
| `Consumer\RedisQueueConsumer` | Drains the Redis queue to the audit service |
| `Replay\FileReplayer` | Delivers JSONL files of closed periods to the audit service |
| `Enricher\EnricherInterface` | Adds context to events inside `log()` |
| `AuditClientFactory`, `Config\AuditClientConfig` | Wiring and configuration |
| `Symfony\AuditFlushSubscriber` | Flushes on `kernel.terminate`, `console.terminate/error`, Messenger worker events |
| `Symfony\RequestEventFactory` | Builder pre-filled with IP, User-Agent, `X-Request-ID` and the `audit_user_id` request attribute |
| `Symfony\Command\ConsumeQueueCommand` | `audit:consume-queue` |
| `Symfony\Command\ReplayFilesCommand` | `audit:replay` |

## Symfony integration

### 1. Environment

```dotenv
AUDIT_ENABLED=true
AUDIT_BASE_URL=https://audit.example.com
REDIS_URL=tcp://127.0.0.1:6379
REDIS_PREFIX=getnative:
```

### 2. Services

```yaml
# config/services.yaml
services:
    # One Predis client for the whole application — the same instance/credentials used by authclient.
    app.predis:
        class: Predis\Client
        arguments: ['%env(REDIS_URL)%']

    Turnkey\AuditClient\Redis\PrefixedRedisClient:
        arguments: ['@app.predis', '%env(REDIS_PREFIX)%']   # same prefix as authclient's PrefixedClient

    Turnkey\AuditClient\Config\AuditClientConfig:
        factory: ['Turnkey\AuditClient\Config\AuditClientConfig', 'fromArray']
        arguments:
            - enabled: '%env(bool:AUDIT_ENABLED)%'
              base_url: '%env(AUDIT_BASE_URL)%'
              service_name: 'getnative'
              file_directory: '%kernel.project_dir%/var/audit'
              # optional: batch_size, file_rotation (daily|hourly), http_timeout, http_max_retries,
              # critical_timeout, critical_max_retries, queue_key, queue_max_size, queue_ttl,
              # consumer_batch_size, replay_batch_size, replay_min_file_age, enricher_budget_ms

    Turnkey\AuditClient\AuditClientFactory:
        autowire: true          # $config (AuditClientConfig) and $logger (LoggerInterface) by type
        arguments:
            $tokenProvider: '@Turnkey\AuthClient\OAuthTokenProvider'   # see php-authclient README
            $http: '@http_client'
            $redis: '@Turnkey\AuditClient\Redis\PrefixedRedisClient'   # or null: chain becomes HTTP -> File

    Turnkey\AuditClient\AuditLoggerInterface:
        factory: ['@Turnkey\AuditClient\AuditClientFactory', 'createLogger']
        arguments: [!tagged_iterator audit.enricher]
        tags: [{ name: kernel.reset, method: reset }]   # FrankenPHP / RoadRunner worker mode

    Turnkey\AuditClient\Consumer\RedisQueueConsumer:
        factory: ['@Turnkey\AuditClient\AuditClientFactory', 'createQueueConsumer']

    Turnkey\AuditClient\Replay\FileReplayer:
        factory: ['@Turnkey\AuditClient\AuditClientFactory', 'createFileReplayer']

    Turnkey\AuditClient\Symfony\AuditFlushSubscriber:
        autowire: true
        tags: [kernel.event_subscriber]

    Turnkey\AuditClient\Symfony\RequestEventFactory:
        autowire: true

    Turnkey\AuditClient\Symfony\Command\ConsumeQueueCommand:
        autowire: true
        tags: [console.command]

    Turnkey\AuditClient\Symfony\Command\ReplayFilesCommand:
        autowire: true
        tags: [console.command]
```

Keep the token provider cached in Redis (authclient `RedisCache`/`FallbackCache`): a cold token fetch counts against the critical-event budget.

### 3. Logging events

```php
use Turnkey\AuditClient\AuditLoggerInterface;
use Turnkey\AuditClient\Exception\CriticalAuditException;
use Turnkey\AuditClient\Symfony\RequestEventFactory;

final class PasswordController
{
    public function __construct(
        private AuditLoggerInterface $audit,
        private RequestEventFactory $events,
        private EntityManagerInterface $em,
    ) {}

    public function change(): Response
    {
        // Regular event: buffered, sent after the response.
        $this->audit->log(
            $this->events->builder()->withAction('password_form_opened')->withSuccess()->build()
        );

        // Critical event: the operation is rolled back if the audit service does not accept it.
        $event = $this->events->builder()
            ->withUser($user->getId())
            ->withAction('password_change')
            ->withResource('user', $user->getId())
            ->withSuccess()
            ->withCritical()
            ->build();

        $this->em->wrapInTransaction(function () use ($event, $user, $hash) {
            $user->setPassword($hash);
            $this->audit->log($event);   // throws CriticalAuditException -> transaction rolled back
        });

        return new Response(status: 204);
    }
}
```

`RequestEventFactory` reads the user ID from the `audit_user_id` request attribute (a `Uuid` or UUID string) — set it in your authentication listener.

### 4. Enrichers

```php
final class TenantEnricher implements EnricherInterface
{
    public function __construct(private TenantContext $tenants) {}

    public function enrich(AuditEvent $event): void
    {
        $event->addContext('tenant', $this->tenants->current());        // goes to "metadata"
        $event->addContext('enrichment.node', gethostname());          // goes to "enrichment_metadata"
    }
}
```

Tag enrichers with `audit.enricher`. They run inside `log()` (the request is still current), must be fast (budget `enricher_budget_ms`, default 100 ms — exceeded budgets are logged) and never overwrite existing keys. Exceptions are logged and do not stop the event.

### 5. Background delivery

```cron
# Redis queue -> audit service
* * * * *   php /app/bin/console audit:consume-queue --time-limit=55 --quiet
# JSONL files -> audit service
*/5 * * * * php /app/bin/console audit:replay --quiet
```

or as supervisor workers:

```ini
[program:audit-consume-queue]
command=php /app/bin/console audit:consume-queue --loop --time-limit=3600
autorestart=true

[program:audit-replay]
command=php /app/bin/console audit:replay --loop --time-limit=3600
autorestart=true
```

| Command | Options |
|---|---|
| `audit:consume-queue` | `--loop`, `--interval=5`, `--max-backoff=300`, `--max-batches=0`, `--time-limit=0`, `--memory-limit=0` (MB) |
| `audit:replay` | `--loop`, `--interval=30`, `--max-backoff=300`, `--time-limit=0`, `--memory-limit=0` (MB) |

Both handle SIGTERM/SIGINT by finishing the current batch (requires `ext-pcntl`).

**audit:consume-queue** pops up to `consumer_batch_size` events (`RPOP key count`, oldest first) and POSTs them. When the service is unavailable the batch is pushed back to the tail (`RPUSH`, order preserved) and the command backs off (loop) or exits (cron). Permanently rejected events (4xx, `retryable=false`) are dropped with an error log. Multiple instances may run in parallel. If the process is killed between `RPOP` and delivery, that batch is lost.

**audit:replay** only touches files of closed periods (not today's/this hour's file, and not files modified within `replay_min_file_age` seconds). A file is claimed by `flock` + rename to `*.replaying`, progress is saved in `*.replaying.offset` after every delivered batch, and the file is deleted when complete — an interrupted replay resumes without re-sending delivered batches. Only one replayer runs at a time (`.replay.lock`).

## Storage formats

- **Redis** — list `{prefix}audit:fallback:queue` (default max 10 000 items, TTL 7 days refreshed on every write). Items and file lines are JSON `FinalAuditEvent` in the same format as Go `json.Marshal(FinalAuditEvent)`:

  ```json
  {"id":"…","user_id":null,"action":"login_success","resource_type":"session","resource_id":null,
   "result":"success","failure_reason":"","created_at":"2026-09-29T10:00:00.123456Z","ip_address":"10.0.0.1",
   "user_agent":"…","request_id":"…","metadata":{},"critical":false,"enrichment_metadata":{},
   "enriched_at":"2026-09-29T10:00:00.124001Z","written_at":null}
  ```

- **Files** — `{file_directory}/audit-YYYY-MM-DD.jsonl` (or `-HH` with hourly rotation), directory `0700`, files `0600`. Each batch is written under `flock`, so all PHP-FPM workers can share one directory. In Kubernetes use a persistent volume shared with the replay process.

- **HTTP** — `POST /api/v1/events/batch` with `{"events":[{event_id, service, action, actor, resource{type,id}, outcome, timestamp, metadata?, failure_reason?}]}`. As in Go, `ip_address`, `user_agent`, `request_id` and enrichment metadata are not part of the wire format.

## Operational notes

- A critical call can take up to ~9.3 s when the audit service is degraded (3 attempts × 3 s + backoff). Tune `critical_timeout` / `critical_max_retries`.
- A regular flush runs after the response but still occupies the PHP-FPM worker: with HTTP down it takes up to ~4.5 s plus Redis/File. Keep it below `request_terminate_timeout`.
- The Redis TTL applies to the whole list: if the audit service is down for longer than `queue_ttl`, the queue expires. Alert on queue length.
- Deliveries are at-least-once (a lost 202 response or a re-sent replay batch); the audit service deduplicates by `event_id` (UUID v7).
- Nested empty arrays inside `metadata` encode as `[]` (PHP cannot distinguish them from empty objects); top-level `metadata` is always an object.

## Differences from go-audit

| go-audit | php-audit |
|---|---|
| Buffered channel + worker goroutine | In-request buffer flushed on `batch_size` / `kernel.terminate` |
| Critical: all writers within 100 ms, error only if all fail | HTTP only, 3 s × 3 attempts, any failure throws |
| `FileWriter` always returns nil | Throws on failure; flock per batch |
| Replayer may delete an active file; re-sends from the start | Closed periods only; rename-claim; offset resume |
| `.failed/` after 10 attempts | Permanent rejections dropped with an error log |
| `RedisQueueConsumer` on the service side (BRPOP → router) | In the client: `RPOP count` → HTTP, `RPUSH` back on failure |
| Metrics published to Redis, FanOut strategy | Not ported |

## Development

Tests run in Docker (`php:8.4-cli`). The Makefile mounts a local checkout of php-authclient (default `../php-authclient`, override with `AUTHCLIENT_DIR=…`) because the container has no SSH access to the private repository.

```bash
make install           # composer install inside the container
make test              # unit tests
make test-integration  # tests against a real Redis 7 container
make lint              # phpstan level 6
```
