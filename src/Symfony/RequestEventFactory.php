<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Symfony;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;
use Turnkey\AuditClient\Event\EventBuilder;

/**
 * Creates EventBuilders pre-filled from the current HTTP request:
 *
 *  - IP address (Request::getClientIp(), honours trusted proxies) and User-Agent
 *  - request ID from the X-Request-ID header when it is a valid UUID
 *  - user ID from the "audit_user_id" request attribute (Uuid or UUID string), set by the
 *    application's authentication layer — the equivalent of Go ContextKeyUserID
 *
 * Outside of a request (console, workers) a plain builder is returned.
 */
final class RequestEventFactory
{
    public const string USER_ID_ATTRIBUTE = 'audit_user_id';
    public const string REQUEST_ID_HEADER = 'X-Request-ID';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function builder(): EventBuilder
    {
        $builder = new EventBuilder();

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return $builder;
        }

        $builder->withHttpContext($request->getClientIp() ?? '', (string) $request->headers->get('User-Agent', ''));

        $requestId = (string) $request->headers->get(self::REQUEST_ID_HEADER, '');
        if ($requestId !== '' && Uuid::isValid($requestId)) {
            $builder->withRequestId(Uuid::fromString($requestId));
        }

        $userId = $request->attributes->get(self::USER_ID_ATTRIBUTE);
        if ($userId instanceof Uuid) {
            $builder->withUser($userId);
        } elseif (is_string($userId) && Uuid::isValid($userId)) {
            $builder->withUser(Uuid::fromString($userId));
        }

        return $builder;
    }
}
