<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Services\SessionResolver;
use Carbon\CarbonImmutable;

/**
 * Where a check-in tapped now lands ({@see SessionResolver::pickForCheckIn()}): a session (with its
 * attendance row when one already exists without a check-in), or the reason none is open.
 */
final readonly class CheckInPick
{
    /** A session is open for the check-in. */
    public const SESSION = 'session';

    /** The next session of today does not accept check-in yet; {@see self::$opensAt} says when it does. */
    public const TOO_EARLY = 'too_early';

    /** Every session around now already has a check-in. */
    public const ALREADY_IN = 'already_in';

    /** The worker has no session today: a check-in is an off-schedule cover. */
    public const OFF_SCHEDULE = 'off_schedule';

    /** The schedule the request named is not one of the worker's working schedules of yesterday, today or tomorrow. */
    public const INVALID_SCHEDULE = 'invalid_schedule';

    /**
     * @param  CarbonImmutable  $date  business date of the session (today when there is none)
     * @param  WorkSession|null  $session  the session booked, or the one not open yet for {@see self::TOO_EARLY}
     * @param  Attendance|null  $attendance  the session's existing row without a check-in, reused by the check-in
     */
    public function __construct(
        public string $outcome,
        public CarbonImmutable $date,
        public ?WorkSession $session = null,
        public ?Attendance $attendance = null,
        public ?CarbonImmutable $opensAt = null,
    ) {}
}
