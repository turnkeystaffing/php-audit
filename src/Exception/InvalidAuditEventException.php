<?php

declare(strict_types=1);

namespace Turnkey\AuditClient\Exception;

/**
 * The event failed validation (missing action/outcome, malformed IP, ...).
 * This is a programming error in the producer, not a delivery failure.
 */
final class InvalidAuditEventException extends AuditException
{
}
