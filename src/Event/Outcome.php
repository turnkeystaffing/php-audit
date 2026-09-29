<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Event;

/**
 * Result of an audited action. Serialized as "result" in the queue/file format
 * and as "outcome" in the HTTP wire format.
 */
enum Outcome: string
{
    /** The action completed successfully. */
    case Success = 'success';

    /** The action failed. FailureReason should describe why. */
    case Failure = 'failure';

    /** The action partially succeeded and requires follow-up. FailureReason should describe what failed. */
    case Partial = 'partial';
}
