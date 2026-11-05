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
     */
    public function __construct(
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $accuracy = null,
        public bool $isMocked = false,
        public ?string $photoPath = null,
        public ?string $livenessChallengeId = null,
        public array $livenessFramePaths = [],
    ) {}
}
