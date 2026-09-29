<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Writer;

use Psr\Log\LoggerInterface;
use Turnkey\AuditClient\Config\HttpDeliveryProfile;
use Turnkey\AuditClient\Exception\DeliveryException;
use Turnkey\AuditClient\Exception\WriteFailedException;
use Turnkey\AuditClient\Http\AuditHttpClient;

/**
 * Tier 1 of the non-critical chain: delivers the batch to the audit service.
 *
 * - whole batch failed        -> WriteFailedException (router falls back to Redis/File)
 * - rejected, retryable=true  -> returned, so the next tier keeps them for a later retry
 * - rejected, retryable=false -> dropped with an error log (retrying will not help)
 */
final class HttpWriter implements AuditWriterInterface
{
    public function __construct(
        private readonly AuditHttpClient $client,
        private readonly HttpDeliveryProfile $profile,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function writeBatch(array $events): array
    {
        try {
            $result = $this->client->send($events, $this->profile);
        } catch (DeliveryException $e) {
            throw new WriteFailedException($this->name(), $e->getMessage(), $e);
        }

        if (!$result->hasRejections()) {
            return [];
        }

        $forward = [];
        foreach ($events as $event) {
            $rejection = $result->rejectionFor($event->id());
            if ($rejection === null) {
                continue;
            }
            if ($rejection->retryable) {
                $forward[] = $event;
                continue;
            }
            $this->logger->error('audit: event rejected by audit service, dropping', [
                'event_id' => $event->id(),
                'action' => $event->event->action,
                'reason' => $rejection->reason,
            ]);
        }

        return $forward;
    }

    public function name(): string
    {
        return 'http';
    }
}
