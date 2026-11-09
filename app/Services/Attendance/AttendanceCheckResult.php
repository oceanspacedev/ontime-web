<?php

namespace App\Services\Attendance;

use App\Models\Attendance;

final readonly class AttendanceCheckResult
{
    /**
     * @param  list<string>  $approvalReasons  Indonesian reasons, e.g. "di luar area kantor".
     */
    public function __construct(
        public Attendance $attendance,
        public bool $requiresApproval,
        public array $approvalReasons,
        public string $message,
    ) {}
}
