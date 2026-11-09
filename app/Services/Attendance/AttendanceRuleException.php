<?php

namespace App\Services\Attendance;

use RuntimeException;

/**
 * A check-in or check-out refused by the company's attendance rules.
 *
 * The message is Indonesian and shown to the worker as is; the status is the HTTP status the
 * API answers with and the field is the form field the message belongs to.
 */
final class AttendanceRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly string $field = 'attendance',
    ) {
        parent::__construct($message);
    }
}
