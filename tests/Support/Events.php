<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Tests\Support;

use Turnkey\AuditClient\Event\AuditEvent;
use Turnkey\AuditClient\Event\EventBuilder;
use Turnkey\AuditClient\Event\FinalAuditEvent;
use Turnkey\AuditClient\Support\Time;

final class Events
{
    public static function event(string $action = 'login_success', bool $critical = false): AuditEvent
    {
        $builder = (new EventBuilder())->withAction($action)->withSuccess()->withResource('session');
        if ($critical) {
            $builder->withCritical();
        }

        return $builder->build();
    }

    public static function final(string $action = 'login_success'): FinalAuditEvent
    {
        return new FinalAuditEvent(self::event($action), [], Time::now());
    }

    /** @return list<FinalAuditEvent> */
    public static function finals(int $count): array
    {
        $events = [];
        for ($i = 0; $i < $count; $i++) {
            $events[] = self::final('action_' . $i);
        }

        return $events;
    }
}
