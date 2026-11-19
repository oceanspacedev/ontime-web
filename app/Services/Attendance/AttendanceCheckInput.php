<?php

namespace App\Services\Attendance;

/**
 * What a worker sends with a check-in or check-out, from the mobile API or the web panel.
 *
 * Photo and liveness frame paths must already be stored and must belong to the worker:
 * the API stores the uploaded files itself, the panel resolves them from upload tokens.
 */
final readonly class AttendanceCheckInput
{
    /**
     * @param  list<string>  $livenessFramePaths  Storage paths in the order of the challenge steps.
     * @param  int|null  $coverShiftId  Check-in outside the schedule: the company shift the worker covers.
     * @param  string|null  $replacesWorker  Check-in outside the schedule: the colleague replaced, as "employee:12".
     * @param  int|null  $scheduleId  Check-in: the worker's schedule row to check in to (the server picks one when absent).
     * @param  int|null  $segment  Check-in: the part (1 or 2) of that schedule.
     * @param  int|null  $coverLocationId  Check-in outside the schedule: the outlet the worker covers at.
     */
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $accuracy = null,
        public bool $isMocked = false,
        public ?string $photoPath = null,
        public ?string $livenessChallengeId = null,
        public array $livenessFramePaths = [],
        public ?int $coverShiftId = null,
        public ?string $replacesWorker = null,
        public ?int $scheduleId = null,
        public ?int $segment = null,
        public ?int $coverLocationId = null,
    ) {}
}
