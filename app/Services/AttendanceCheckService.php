<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceCheckResult;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\Attendance\CheckInPick;
use App\Services\Attendance\WorkSession;
use App\Services\Face\FaceMatch;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;
use App\Support\SelfieUploadToken;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The single rule set for check-in and check-out, shared by the mobile API and the web panel.
 *
 * The company policy decides the mode: GPS (geofence and mock location) on or off, and a selfie
 * mode of none, photo, face or liveness. Refusals are thrown as {@see AttendanceRuleException};
 * soft failures (outside the office, fake location or face mismatch when the policy allows it)
 * are recorded and sent to the approver as a pending approval.
 */
class AttendanceCheckService
{
    public const PURPOSE_CHECK_IN = 'check_in';

    public const PURPOSE_CHECK_OUT = 'check_out';

    private const DEFAULT_FACE_THRESHOLD = 60;

    public const ALREADY_CHECKED_IN_MESSAGE = 'Sudah melakukan check in hari ini.';

    public const ALREADY_CHECKED_OUT_MESSAGE = 'Sudah melakukan check out hari ini.';

    public const ON_LEAVE_MESSAGE = 'Hari ini Anda tercatat cuti atau izin yang sudah disetujui, jadi tidak perlu absen.';

    public const OPEN_OVERNIGHT_MESSAGE = 'Selesaikan absen pulang shift semalam dulu.';

    /** Yesterday's day shift was never checked out: its real end time is filed as a correction, not tapped now. */
    public const FORGOTTEN_CHECK_OUT_MESSAGE = 'Absen pulang kemarin terlewat. Ajukan koreksi presensi untuk mencatat jam pulangnya.';

    /** An earlier session of today was left open and the worker checked in elsewhere since: filed as a correction too. */
    public const FORGOTTEN_EARLIER_SESSION_MESSAGE = 'Absen pulang sesi sebelumnya hari ini terlewat. Ajukan koreksi presensi untuk mencatat jam pulangnya.';

    /** The roster of the date changed between reading it and saving the check-in (for example a replacement approved meanwhile). */
    public const ROSTER_CHANGED_MESSAGE = 'Jadwal Anda hari ini baru saja berubah. Muat ulang lalu coba absen lagi.';

    /**
     * A rejected attendance ends there: a check-out would open a new approval round whose approval
     * turns the rejected day into a valid one. Only an approved correction that gives the real check-in
     * time (and the check-out) revives it.
     */
    public const REJECTED_CHECK_OUT_MESSAGE = 'Presensi ini sudah ditolak atasan, jadi tidak bisa check out. Bila Anda memang bekerja, ajukan koreksi dengan jam masuk dan jam pulang yang sebenarnya.';

    /**
     * The approver refused the check-out (not the check-in): the row keeps its check-in and its real end
     * time is filed as a correction.
     */
    public const CHECK_OUT_REFUSED_MESSAGE = 'Absen pulang Anda ditolak atasan. Ajukan koreksi presensi untuk mencatat jam pulangnya.';

    /** An overnight row of yesterday counts as still open for this many hours after its check-in. */
    public const OVERNIGHT_OPEN_HOURS = 20;

    /** How many days back a forgotten check-out still answers a check-out tap with the correction message. */
    public const FORGOTTEN_LOOKBACK_DAYS = 7;

    /** The check-in named a schedule that is not one of the worker's working schedules around today. */
    public const UNKNOWN_SCHEDULE_MESSAGE = 'Jadwal yang dipilih tidak ditemukan atau bukan jadwal Anda hari ini.';

    /** Approval reason of a cover whose outlet is neither named nor matched by the geofence. */
    public const OUTLET_UNKNOWN_REASON = 'outlet belum ditentukan';

    public function __construct(
        private readonly WorkScheduleService $workSchedule,
        private readonly ApprovalFlowService $approvalFlow,
    ) {}

    /**
     * Start a liveness challenge for a worker whose company requires liveness.
     *
     * @return array{challenge_id: string, steps: list<array{key: string, label: string, instruction: string}>, expires_at: string, expires_in: int}
     *
     * @throws AttendanceRuleException
     */
    public function livenessChallenge(User $user, string $purpose): array
    {
        $profile = $this->profileOf($user);

        if (! in_array($purpose, LivenessService::PURPOSES, true)) {
            throw new AttendanceRuleException('Jenis presensi tidak dikenali.', 422, 'purpose');
        }

        if ($profile->company?->policy?->selfieMode() !== 'liveness') {
            throw new AttendanceRuleException('Liveness tidak diwajibkan untuk badan usaha Anda.', 422, 'purpose');
        }

        try {
            return app(LivenessService::class)->issueChallenge($user, $purpose);
        } catch (FaceServiceUnavailable $exception) {
            throw new AttendanceRuleException($exception->getMessage(), 503, 'purpose');
        }
    }

    /**
     * The uploads in $input are owned by this call: a refused attempt removes them, and a successful
     * one removes those the saved record does not use (for example a photo sent in liveness mode).
     *
     * @throws AttendanceRuleException
     */
    public function checkIn(User $user, AttendanceCheckInput $input): AttendanceCheckResult
    {
        return $this->withUploadCleanup($user, $input, fn (): AttendanceCheckResult => $this->performCheckIn($user, $input));
    }

    /**
     * The uploads in $input are owned by this call, see {@see self::checkIn()}.
     *
     * @throws AttendanceRuleException
     */
    public function checkOut(User $user, AttendanceCheckInput $input): AttendanceCheckResult
    {
        return $this->withUploadCleanup($user, $input, fn (): AttendanceCheckResult => $this->performCheckOut($user, $input));
    }

    /**
     * Delete uploaded files of an attempt, except files a stored record still uses.
     *
     * A web upload token stays valid for {@see SelfieUploadToken::VALID_FOR_MINUTES} minutes and can be
     * submitted again, so a path may already be evidence of an earlier check (for example yesterday's
     * check-in photo when the token is replayed after midnight) or someone's master face photo (an
     * admin uploads it with their own token). Such paths are always kept.
     *
     * @param  array<int, string|null>  $paths
     */
    public function discardUploads(User $user, array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths, filled(...))));

        if ($paths === []) {
            return;
        }

        $unused = array_values(array_diff($paths, $this->pathsInUse($user, $paths)));

        if ($unused !== []) {
            Storage::disk(config('filesystems.default'))->delete($unused);
        }
    }

    /**
     * The given paths that are a master face photo of any worker, or a photo or liveness frame of
     * any attendance of this worker, on any date.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function pathsInUse(User $user, array $paths): array
    {
        $inUse = [];

        foreach ([Employee::class, Intern::class, Freelancer::class] as $profileModel) {
            array_push($inUse, ...$profileModel::query()->whereIn('master_face_photo', $paths)->pluck('master_face_photo')->all());
        }

        $attendances = Attendance::byWorker($user)
            ->where(function ($query) use ($paths): void {
                $query->whereIn('check_in_photo', $paths)
                    ->orWhereIn('check_out_photo', $paths);

                foreach ($paths as $path) {
                    $query->orWhereJsonContains('liveness_frames', $path);
                }
            })
            ->get(['check_in_photo', 'check_out_photo', 'liveness_frames']);

        foreach ($attendances as $attendance) {
            array_push($inUse, $attendance->check_in_photo, $attendance->check_out_photo, ...($attendance->liveness_frames ?? []));
        }

        return array_values(array_intersect($paths, array_filter($inUse)));
    }

    /**
     * Any failure (a refusal, a lost race on the unique index, a database or face service error)
     * discards the uploads of the attempt before the error goes on.
     *
     * @param  callable(): AttendanceCheckResult  $check
     *
     * @throws AttendanceRuleException
     */
    private function withUploadCleanup(User $user, AttendanceCheckInput $input, callable $check): AttendanceCheckResult
    {
        $uploads = [$input->photoPath, ...$input->livenessFramePaths];

        try {
            $result = $check();
        } catch (Throwable $exception) {
            $this->discardUploads($user, $uploads);

            throw $exception;
        }

        $this->discardUploads($user, $uploads);

        return $result;
    }

    /**
     * @throws AttendanceRuleException
     */
    private function performCheckIn(User $user, AttendanceCheckInput $input): AttendanceCheckResult
    {
        $profile = $this->workerProfile($user);
        $pick = app(SessionResolver::class)->pickForCheckIn($profile, now(), $input->scheduleId, $input->segment);

        if ($pick->outcome === CheckInPick::INVALID_SCHEDULE) {
            throw new AttendanceRuleException(self::UNKNOWN_SCHEDULE_MESSAGE, 422, 'schedule_id');
        }

        // A night shift covered after midnight belongs to yesterday, when yesterday was not a working day either.
        $coverDate = $pick->outcome !== CheckInPick::SESSION && $input->scheduleId === null
            ? $this->overnightCoverDate($user, $profile, $input->coverShiftId)
            : null;
        $businessDate = CarbonImmutable::parse(($coverDate ?? $pick->date)->toDateString());
        $isCover = $coverDate !== null
            || $pick->outcome === CheckInPick::OFF_SCHEDULE
            // Every session of the day is worked: a further shift is covered like one outside the schedule.
            || ($pick->outcome === CheckInPick::ALREADY_IN && $input->coverShiftId !== null && $input->scheduleId === null);

        // Judged on the day the shift belongs to: a night shift starting on the first contract day is allowed.
        $this->ensureActive($profile, $businessDate);
        $dayRows = Attendance::byWorker($user)->with('location')->whereDate('date', $businessDate->toDateString())->get();

        // An approved leave writes the day's row without a check-in; checking in would overwrite the leave.
        if ($dayRows->contains(fn (Attendance $row): bool => $this->isApprovedLeave($row))) {
            throw new AttendanceRuleException(self::ON_LEAVE_MESSAGE, 409);
        }

        // An earlier session is still running (before its scheduled end, or last night's shift after midnight):
        // it is checked out first. Past that, a check-in to another session leaves it as a missing check-out.
        if ($open = $this->blockingOpenAttendance($user)) {
            throw new AttendanceRuleException($open->date->lessThan($businessDate) ? self::OPEN_OVERNIGHT_MESSAGE : self::ALREADY_CHECKED_IN_MESSAGE, 409);
        }

        if (! $isCover && $pick->outcome === CheckInPick::ALREADY_IN) {
            throw new AttendanceRuleException(self::ALREADY_CHECKED_IN_MESSAGE, 409);
        }

        // Checking in hours before a session starts would close it as "on time" for a presence nobody worked.
        if (! $isCover && $pick->outcome === CheckInPick::TOO_EARLY && $pick->session && $pick->opensAt) {
            throw $this->tooEarly($pick->session, $pick->opensAt);
        }

        /** @var array{reason: string, replaced_worker_type: string|null, replaced_worker_id: int|null, replaced_schedule_id: int|null}|null $offSchedule */
        $offSchedule = null;

        if ($isCover) {
            [$workingHours, $offSchedule] = $this->offScheduleHours($profile, $businessDate, $input);
            $workingHours['sequence'] = $this->freeSequence($profile, $businessDate, $dayRows);
            $existingAttendance = $dayRows->first(fn (Attendance $row): bool => (int) ($row->schedule_sequence ?: 1) === $workingHours['sequence'] && (int) $row->segment === 1 && $row->check_in === null);
            $opensAt = $businessDate->setTimeFromTimeString((string) $workingHours['start_time'])->subMinutes(SessionResolver::CHECK_IN_OPENS_MINUTES_BEFORE);

            if (now()->lessThan($opensAt)) {
                throw new AttendanceRuleException(
                    sprintf('Shift dimulai pukul %s. Absen masuk dibuka pukul %s.', substr((string) $workingHours['start_time'], 0, 5), $opensAt->format('H:i')),
                    422,
                );
            }

            // Never a cover of a shift that is already over on its date: it could only be booked very late and left open.
            $coverShift = Shift::query()->find($input->coverShiftId);
            $lastPart = $coverShift ? last($coverShift->segments()) : null;

            if ($lastPart && now()->getTimestamp() >= AttendanceFigures::window($businessDate->toDateString(), $lastPart['start'], $lastPart['end'])[1]) {
                throw new AttendanceRuleException(
                    sprintf('Shift %s pada tanggal ini sudah selesai pukul %s. Pilih shift yang sedang atau akan berjalan.', $coverShift->name, substr((string) $lastPart['end'], 0, 5)),
                    422,
                );
            }
        } else {
            /** @var WorkSession $session */
            $session = $pick->session;
            $workingHours = $session->toWorkingHours();
            $existingAttendance = $pick->attendance;

            // A later part of a split shift covered outside the roster waits for approval as its first part did.
            if ($session->source === 'cover') {
                $offSchedule = $this->coverOfSibling($dayRows, $session->sequence);
            }
        }

        $checks = $this->runChecks($user, $profile, $input, self::PURPOSE_CHECK_IN, $workingHours['location']);
        $reasons = $checks['approval_reasons'];

        if ($offSchedule) {
            // A cover records where it was worked: the outlet whose geofence the worker is in, when none was named.
            if ($workingHours['location'] === null) {
                $workingHours['location'] = $this->matchedOutlet($profile, $input, $checks['matched_location_id']);
            }

            array_unshift($reasons, ...array_filter([$offSchedule['reason'], $workingHours['location'] === null ? self::OUTLET_UNKNOWN_REASON : null]));
        }

        $requiresApproval = $reasons !== [];

        // Stored to the second, and judged on that same stored moment: a later re-judgement of the row agrees.
        $now = now()->startOfSecond();
        // Judged now, against the schedule of this moment. A check-in waiting for approval is not late
        // yet in reports; the approval applies this value later (see Attendance::applyGeofenceApproval()).
        $checkInLateMinutes = $this->workSchedule->lateMinutes($workingHours, $now, $businessDate);
        $lateMinutes = $requiresApproval ? 0 : $checkInLateMinutes;
        $status = match (true) {
            $requiresApproval => AttendanceStatus::PendingApproval,
            $lateMinutes > 0 => AttendanceStatus::Late,
            default => AttendanceStatus::OnTime,
        };

        try {
            $attendance = $this->saveCheckIn($user, $profile, $existingAttendance, $businessDate, $checks, $workingHours, $now, $status, $lateMinutes, $checkInLateMinutes, $requiresApproval, $offSchedule);
        } catch (UniqueConstraintViolationException) {
            // Another write created the session's row first: a leave approved meanwhile, or a second request
            // of the same tap (double submit, retry) that won the race on the unique attendance index.
            $winner = Attendance::byWorker($user)
                ->whereDate('date', $businessDate->toDateString())
                ->where('schedule_sequence', $workingHours['sequence'])
                ->where('segment', $workingHours['segment'])
                ->first();

            if ($this->isApprovedLeave($winner)) {
                throw new AttendanceRuleException(self::ON_LEAVE_MESSAGE, 409);
            }

            throw new AttendanceRuleException(self::ALREADY_CHECKED_IN_MESSAGE, 409);
        }

        return new AttendanceCheckResult(
            attendance: $attendance->fresh() ?? $attendance,
            requiresApproval: $requiresApproval,
            approvalReasons: $reasons,
            message: $this->message('Check in', $reasons),
        );
    }

    /**
     * The refusal of a check-in before the session opens, naming its opening time.
     */
    private function tooEarly(WorkSession $session, CarbonImmutable $opensAt): AttendanceRuleException
    {
        $start = $session->startAt->format('H:i');

        return new AttendanceRuleException(
            $session->segment === 2
                ? sprintf('Segmen kedua dimulai pukul %s. Check in dibuka pukul %s.', $start, $opensAt->format('H:i'))
                : sprintf('%s dimulai pukul %s. Absen masuk dibuka pukul %s.', $session->shift ? 'Shift' : 'Jam kerja', $start, $opensAt->format('H:i')),
            422,
        );
    }

    /**
     * The off-schedule details of the first part of a split shift covered outside the roster, which a later
     * part of the same cover repeats (the reason and the colleague replaced).
     *
     * @param  Collection<int, Attendance>  $dayRows
     * @return array{reason: string, replaced_worker_type: string|null, replaced_worker_id: int|null, replaced_schedule_id: int|null}
     */
    private function coverOfSibling(Collection $dayRows, int $sequence): array
    {
        $first = $dayRows->first(fn (Attendance $row): bool => $row->is_off_schedule && (int) ($row->schedule_sequence ?: 1) === $sequence && $row->check_in !== null);
        $replaced = $first?->replaced_worker_type && $first->replaced_worker_id
            ? $this->offSchedule()->workerFromKey($first->replaced_worker_type.':'.$first->replaced_worker_id)
            : null;

        return [
            'reason' => $this->offSchedule()->reason($replaced),
            'replaced_worker_type' => $first?->replaced_worker_type,
            'replaced_worker_id' => $first?->replaced_worker_id ? (int) $first->replaced_worker_id : null,
            'replaced_schedule_id' => $first?->replaced_schedule_id ? (int) $first->replaced_schedule_id : null,
        ];
    }

    /**
     * The lowest schedule number of the date that no working roster row and no checked-in attendance holds:
     * where an off-schedule check-in is filed (a day off of that number is the row its approval converts).
     *
     * @param  Collection<int, Attendance>  $dayRows
     */
    private function freeSequence(Employee|Intern|Freelancer $profile, CarbonInterface $businessDate, Collection $dayRows): int
    {
        $taken = ShiftSchedule::query()
            ->where($this->workSchedule->workerColumn($profile), $profile->getKey())
            ->whereDate('date', $businessDate->toDateString())
            ->where('is_day_off', false)
            ->whereNotNull('shift_id')
            ->pluck('sequence')
            ->merge($dayRows->filter(fn (Attendance $row): bool => $row->check_in !== null)->pluck('schedule_sequence'))
            ->map(fn (mixed $sequence): int => (int) ($sequence ?: 1))
            ->all();

        $sequence = 1;

        while (in_array($sequence, $taken, true)) {
            $sequence++;
        }

        return $sequence;
    }

    /**
     * The active outlet of the worker's company whose geofence the check-in position is in, when the
     * check-in names no outlet: from the geofence check itself, or matched here when GPS is not required.
     */
    private function matchedOutlet(Employee|Intern|Freelancer $profile, AttendanceCheckInput $input, ?int $matchedId): ?CompanyLocation
    {
        if ($matchedId === null && $profile->company && $input->latitude !== null && $input->longitude !== null) {
            $matchedId = GeofenceService::validateCompanyGeofence($profile->company, $input->latitude, $input->longitude)['matched_location_id'] ?? null;
        }

        return $matchedId ? CompanyLocation::query()->where('is_active', true)->find($matchedId) : null;
    }

    /**
     * @param  array<string, mixed>  $checks
     * @param  array<string, mixed>  $workingHours  the session's hours, with its schedule_id, sequence and segment
     * @param  array{reason: string, replaced_worker_type: string|null, replaced_worker_id: int|null, replaced_schedule_id: int|null}|null  $offSchedule
     */
    private function saveCheckIn(
        User $user,
        Employee|Intern|Freelancer $profile,
        ?Attendance $existingAttendance,
        CarbonInterface $businessDate,
        array $checks,
        array $workingHours,
        CarbonInterface $now,
        AttendanceStatus $status,
        int $lateMinutes,
        int $checkInLateMinutes,
        bool $requiresApproval,
        ?array $offSchedule = null,
    ): Attendance {
        return DB::transaction(function () use ($user, $profile, $existingAttendance, $businessDate, $checks, $workingHours, $now, $status, $lateMinutes, $checkInLateMinutes, $requiresApproval, $offSchedule): Attendance {
            $sessions = app(SessionResolver::class);
            $sequence = (int) ($workingHours['sequence'] ?? 1);
            $segment = (int) ($workingHours['segment'] ?? 1);
            // The schedule the check-in was judged against is kept on the row: the roster row it belongs to
            // (the one locked below), its hours, tolerance and names. An off-schedule check-in is a cover.
            $snapshot = $sessions->snapshotFor($sessions->sessionFromHours($profile, $workingHours, $businessDate), $offSchedule !== null ? 'cover' : null);
            $snapshot['schedule_sequence'] = $sequence;

            // The roster is read again under a lock, the one an approved replacement takes before it gives this
            // worker the day off (OffScheduleCheckInService::applyApproval()): a check-in judged against a
            // rostered session is refused when that day off, or another shift or outlet, landed in between.
            if ($offSchedule === null) {
                $link = $this->lockedRoster($profile, $businessDate, $workingHours);
                $snapshot['shift_schedule_id'] = $link?->getKey();
                $snapshot['sched_source'] = $link ? 'schedule' : ($workingHours['shift'] !== null ? 'default' : $snapshot['sched_source']);
            }

            // A colleague is replaced by one backup: checked again under the colleague's roster lock.
            if ($offSchedule !== null && $offSchedule['replaced_worker_type'] !== null && $offSchedule['replaced_worker_id'] !== null && $segment === 1) {
                $this->offSchedule()->lockReplacement($offSchedule['replaced_worker_type'], $offSchedule['replaced_worker_id'], $businessDate, $profile, $workingHours['shift']?->getKey(), $offSchedule['replaced_schedule_id']);
            }

            // Reuse the session's row (for example one created by an approved correction) instead of inserting a
            // second row, which the unique (worker, date, schedule_sequence, segment) index rejects. An update
            // never trips that index, so the row is read again under a lock: a concurrent check-in (double tap,
            // retry) or a leave approved meanwhile is refused instead of overwritten.
            if ($existingAttendance) {
                $existingAttendance = Attendance::query()->whereKey($existingAttendance->getKey())->lockForUpdate()->first();

                if ($existingAttendance?->check_in) {
                    throw new AttendanceRuleException(self::ALREADY_CHECKED_IN_MESSAGE, 409);
                }

                if ($this->isApprovedLeave($existingAttendance)) {
                    throw new AttendanceRuleException(self::ON_LEAVE_MESSAGE, 409);
                }
            }

            $attendance = $existingAttendance ?? new Attendance([
                ...Attendance::workerKeys($user),
                'date' => $businessDate->toDateString(),
                'segment' => $segment,
            ]);

            // A legacy row may carry a check-out without any check-in: that time belongs to no session.
            if ($attendance->exists && $attendance->check_out !== null) {
                $attendance->fill(['check_out' => null, 'check_out_lat' => null, 'check_out_lng' => null, 'check_out_photo' => null]);
            }

            $attendance->fill([
                'shift_id' => $workingHours['shift']?->id,
                'company_location_id' => $workingHours['location']?->id,
                'check_in' => $now,
                'check_in_lat' => $checks['latitude'],
                'check_in_lng' => $checks['longitude'],
                'check_in_photo' => $checks['photo_path'],
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'check_in_late_minutes' => $checkInLateMinutes,
                'is_out_of_bounds' => $checks['is_out_of_bounds'],
                'is_mock_location' => $checks['is_mock_location'],
                'is_face_verified' => $checks['is_face_verified'],
                'face_match_score' => $checks['face_match_score'],
                'face_verification_notes' => $checks['face_verification_notes'],
                'liveness_verified' => $checks['liveness_verified'],
                'liveness_notes' => $checks['liveness_notes'],
                'liveness_frames' => $checks['liveness_frames'],
                'is_off_schedule' => $offSchedule !== null,
                'replaced_worker_type' => $offSchedule['replaced_worker_type'] ?? null,
                'replaced_worker_id' => $offSchedule['replaced_worker_id'] ?? null,
                'replaced_schedule_id' => $offSchedule['replaced_schedule_id'] ?? null,
                // The approval round of this row is about the check-in (a check-out tapped meanwhile waits with it).
                'pending_punch' => $requiresApproval ? Attendance::PUNCH_CHECK_IN : null,
                'status_before_check_out' => null,
                'check_out_rejected_at' => null,
                'checks_before_check_out' => null,
            ]);
            $attendance->fillSnapshot($snapshot);

            // The approver reads why this check-in waits ("Masuk di luar jadwal (menggantikan Budi)").
            if ($offSchedule && blank($attendance->notes)) {
                $attendance->notes = ucfirst($offSchedule['reason']);
            }

            $attendance->save();

            if ($requiresApproval) {
                $this->approvalFlow->generateSteps($attendance, 'geofence');
            }

            return $attendance;
        });
    }

    /**
     * Read the roster the session comes from again under a lock and return the schedule row the check-in is
     * linked to (null for standing hours). A rostered session needs its row unchanged (same shift and outlet,
     * not a day off); standing hours need the date to have gained no day off and no shift, and the worker's
     * default shift and placement to be the ones judged against.
     *
     * @param  array<string, mixed>  $workingHours
     *
     * @throws AttendanceRuleException
     */
    private function lockedRoster(Employee|Intern|Freelancer $profile, CarbonInterface $businessDate, array $workingHours): ?ShiftSchedule
    {
        $rows = ShiftSchedule::query()
            ->where($this->workSchedule->workerColumn($profile), $profile->getKey())
            ->whereDate('date', $businessDate->toDateString())
            ->when($workingHours['schedule_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->lockForUpdate()
            ->get();
        $shiftId = (int) $workingHours['shift']?->getKey();
        $locationId = (int) $workingHours['location']?->getKey();

        if ($scheduleId = $workingHours['schedule_id'] ?? null) {
            $row = $rows->first(fn (ShiftSchedule $row): bool => (int) $row->getKey() === (int) $scheduleId);

            if (! $row || $row->is_day_off || (int) $row->shift_id !== $shiftId || (int) $row->company_location_id !== $locationId) {
                throw new AttendanceRuleException(self::ROSTER_CHANGED_MESSAGE, 409);
            }

            return $row;
        }

        $placement = $rows->first(fn (ShiftSchedule $row): bool => ! $row->is_day_off && $row->shift_id === null);
        $changed = $rows->contains(fn (ShiftSchedule $row): bool => $row->is_day_off || $row->shift_id !== null)
            || (int) ($profile->shift_id ? $profile->shift_id : 0) !== $shiftId
            || ($placement !== null && (int) $placement->company_location_id !== $locationId);

        if ($changed) {
            throw new AttendanceRuleException(self::ROSTER_CHANGED_MESSAGE, 409);
        }

        return null;
    }

    /**
     * @throws AttendanceRuleException
     */
    private function performCheckOut(User $user, AttendanceCheckInput $input): AttendanceCheckResult
    {
        $profile = $this->workerProfile($user);
        $attendance = $this->attendanceToCheckOut($user);
        // The row's own day decides (a night shift on the last contract day closes the next morning); a row
        // that already exists also closes on a day the worker may work. An open row of the last working day
        // still closes after the employment ended meanwhile (the nightly close, or "Akhiri Kontrak" mid-shift).
        if (! $this->isOpenRowOfLastWorkingDay($profile, $attendance)) {
            $this->ensureActive($profile, $attendance?->date ?? today(), today());
        }

        if (! $attendance?->check_in) {
            // An earlier shift left open past its check-out window: tapping now would record now as its end time.
            // One whose check-out the approver refused (for example at 23:30, tapped again after midnight) says so.
            if ($forgotten = $this->forgottenCheckOut($user)) {
                throw new AttendanceRuleException($forgotten->check_out_rejected_at ? self::CHECK_OUT_REFUSED_MESSAGE : self::FORGOTTEN_CHECK_OUT_MESSAGE, 422);
            }

            throw new AttendanceRuleException('Anda belum melakukan check in hari ini.', 422);
        }

        if ($attendance->check_out) {
            throw new AttendanceRuleException(self::ALREADY_CHECKED_OUT_MESSAGE, 409);
        }

        if ($attendance->status === AttendanceStatus::Rejected) {
            throw new AttendanceRuleException(self::REJECTED_CHECK_OUT_MESSAGE, 409);
        }

        // Open, but no longer closable live (its check-out was refused, or its window has passed).
        if (! $this->isLiveClosable($attendance)) {
            throw new AttendanceRuleException($this->notClosableMessage($attendance), 422);
        }

        $checks = $this->runChecks($user, $profile, $input, self::PURPOSE_CHECK_OUT, $attendance->location);
        $reasons = $checks['approval_reasons'];
        $requiresApproval = $reasons !== [];
        // Stored to the second, so the stored time is the one every later judgement reads.
        $checkOutAt = now()->startOfSecond();

        try {
            $attendance = DB::transaction(function () use ($attendance, $checks, $checkOutAt, $requiresApproval): Attendance {
                // The row was read before the (slow) face and liveness checks; read it again under a lock
                // so a concurrent check-out (double tap, retry) is refused instead of overwriting the first.
                $attendance = Attendance::query()->whereKey($attendance->getKey())->lockForUpdate()->first();

                if (! $attendance || $attendance->check_out) {
                    throw new AttendanceRuleException(self::ALREADY_CHECKED_OUT_MESSAGE, 409);
                }

                if ($attendance->status === AttendanceStatus::Rejected) {
                    throw new AttendanceRuleException(self::REJECTED_CHECK_OUT_MESSAGE, 409);
                }

                $updates = [
                    'check_out' => $checkOutAt,
                    'check_out_lat' => $checks['latitude'],
                    'check_out_lng' => $checks['longitude'],
                    'check_out_photo' => $checks['photo_path'],
                    'is_out_of_bounds' => $checks['is_out_of_bounds'] || $attendance->is_out_of_bounds,
                    'is_mock_location' => $checks['is_mock_location'] || $attendance->is_mock_location,
                    'is_face_verified' => $checks['is_face_verified'] ?? $attendance->is_face_verified,
                    'face_match_score' => $checks['face_match_score'] ?? $attendance->face_match_score,
                    'face_verification_notes' => $checks['face_verification_notes'] ?? $attendance->face_verification_notes,
                    'liveness_verified' => $checks['liveness_verified'] ?? $attendance->liveness_verified,
                    'liveness_notes' => $checks['liveness_notes'] ?? $attendance->liveness_notes,
                    'liveness_frames' => $checks['liveness_frames'] ?? $attendance->liveness_frames,
                ];

                // A settled check-in keeps its verdict aside while only the check-out waits, so a refusal
                // voids the check-out alone; a check-in still waiting takes the check-out into its own round.
                if ($requiresApproval && $attendance->status !== AttendanceStatus::PendingApproval) {
                    $updates['status_before_check_out'] = $attendance->status?->value ?? AttendanceStatus::OnTime->value;
                    $updates['pending_punch'] = Attendance::PUNCH_CHECK_OUT;
                    // The check-in's own verification results, put back if this check-out is refused.
                    $updates['checks_before_check_out'] = $attendance->only(Attendance::CHECK_RESULT_COLUMNS);
                }

                if ($requiresApproval) {
                    $updates['status'] = AttendanceStatus::PendingApproval;
                }

                $attendance->update($updates);

                if ($requiresApproval && $attendance->needsNewApprovalRound()) {
                    $this->approvalFlow->generateSteps($attendance, 'geofence');
                }

                return $attendance;
            });
        } catch (UniqueConstraintViolationException) {
            throw new AttendanceRuleException(self::ALREADY_CHECKED_IN_MESSAGE, 409);
        }

        return new AttendanceCheckResult(
            attendance: $attendance->fresh() ?? $attendance,
            requiresApproval: $requiresApproval,
            approvalReasons: $reasons,
            message: $this->message('Check out', $reasons),
        );
    }

    /**
     * The row is checked in, not checked out, and dated on or before the worker's last working day.
     */
    private function isOpenRowOfLastWorkingDay(Employee|Intern|Freelancer $profile, ?Attendance $attendance): bool
    {
        if (! $attendance?->check_in || $attendance->check_out) {
            return false;
        }

        return app(WorkerAccountService::class)->mayCloseShiftOf($profile, $attendance->date);
    }

    /**
     * A row written by an approved leave ({@see LeaveRequest::applyLeave()}): leave status, no check-in, and a
     * leave request of the same worker that is still approved and charges the row's date
     * ({@see LeaveRequest::chargedDateStrings()}, the list every leave reader uses). Deleting or re-dating a
     * leave undoes its rows (the deleted hook and {@see LeaveRequest::syncAttendanceRows()}); a leave row left
     * over from before that (no leave charges its date any more) is checked in over as usual.
     */
    private function isApprovedLeave(?Attendance $attendance): bool
    {
        if ($attendance === null || $attendance->check_in !== null || $attendance->status !== AttendanceStatus::Leave) {
            return false;
        }

        $workerKeys = array_filter([
            'employee_id' => $attendance->employee_id,
            'intern_id' => $attendance->intern_id,
            'freelancer_id' => $attendance->freelancer_id,
        ]);

        if ($workerKeys === []) {
            return false;
        }

        $date = $attendance->date->toDateString();

        return LeaveRequest::query()
            ->where($workerKeys)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->get(['start_date', 'end_date', 'charged_dates'])
            ->contains(fn (LeaveRequest $leave): bool => in_array($date, $leave->chargedDateStrings(), true));
    }

    /**
     * The row a check-out tap closes: the live-closable row ({@see self::closableAttendance()}), else the
     * session the worker checked in to last today (a later session already checked out says so, an earlier
     * one left open is a missing check-out), else a rejected session of yesterday still inside its check-out
     * window (an overnight shift tapped after midnight), else today's first row. That row then decides the
     * refusal (already checked out, rejected, missing check-out, or not checked in).
     */
    private function attendanceToCheckOut(User $user): ?Attendance
    {
        if ($closable = $this->closableAttendance($user)) {
            return $closable;
        }

        $todayRows = Attendance::byWorker($user)->with('location')->today()->orderByDesc('segment')->get();
        $latest = $todayRows->filter(fn (Attendance $row): bool => $row->check_in !== null)
            ->sortByDesc(fn (Attendance $row): int => $row->check_in->getTimestamp())
            ->first();

        if ($latest) {
            return $latest;
        }

        $rejectedOvernight = Attendance::byWorker($user)
            ->with('location')
            ->whereDate('date', today()->subDay())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('status', AttendanceStatus::Rejected)
            ->orderByDesc('check_in')
            ->get()
            ->first(fn (Attendance $row): bool => ($closesAt = app(SessionResolver::class)->checkOutClosesAt($row)) === null || now()->lessThanOrEqualTo($closesAt));

        return $rejectedOvernight ?? $todayRows->first();
    }

    /**
     * The worker's open row (checked in, not checked out, not rejected) of yesterday, today or tomorrow with
     * the latest check-in, while it can still be checked out live ({@see self::isLiveClosable()}): a closing
     * shift ending at 22:30 is closed at 00:10 on its own business date. The API, the web panel and the
     * check-out itself all offer this row.
     */
    public function closableAttendance(User $user): ?Attendance
    {
        return Attendance::byWorker($user)
            ->with(['location', 'shift'])
            ->whereDate('date', '>=', today()->subDay())
            ->whereDate('date', '<=', today()->addDay())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->orderByDesc('check_in')
            ->orderByDesc('segment')
            ->get()
            ->first(fn (Attendance $row): bool => $this->isLiveClosable($row));
    }

    /**
     * Whether an open row can still be checked out live: its check-out was not refused, now is not past
     * {@see SessionResolver::checkOutClosesAt()} (the scheduled end plus the grace hours, the end of its
     * business date, or for an overnight row its check-in plus {@see self::OVERNIGHT_OPEN_HOURS} hours), and
     * the worker has not checked in anywhere since. Past that, the row is a missing check-out.
     */
    public function isLiveClosable(Attendance $attendance): bool
    {
        if (! $attendance->check_in || $attendance->check_out || $attendance->status === AttendanceStatus::Rejected || $attendance->check_out_rejected_at) {
            return false;
        }

        $closesAt = app(SessionResolver::class)->checkOutClosesAt($attendance);

        if ($closesAt && now()->startOfSecond()->greaterThan($closesAt)) {
            return false;
        }

        $workerKeys = array_filter([
            'employee_id' => $attendance->employee_id,
            'intern_id' => $attendance->intern_id,
            'freelancer_id' => $attendance->freelancer_id,
        ]);

        if ($workerKeys === []) {
            return true;
        }

        return Attendance::query()
            ->where($workerKeys)
            ->whereKeyNot($attendance->getKey())
            ->where('check_in', '>', $attendance->check_in)
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->doesntExist();
    }

    /**
     * Yesterday's live-closable row of an overnight shift (its hours cross midnight). Until it is checked
     * out, the app offers its check-out and a new check-in is refused.
     */
    public function openOvernightAttendance(User $user): ?Attendance
    {
        $open = $this->closableAttendance($user);

        return $open && $open->date->isSameDay(today()->subDay()) && $this->crossesMidnight($user, $open) ? $open : null;
    }

    /**
     * The worker's live-closable row that holds any new check-in back: until its scheduled end, and for a
     * session ending on the next calendar day within {@see self::OVERNIGHT_OPEN_HOURS} hours of its check-in.
     * Past that, a check-in to another session is allowed and the row becomes a missing check-out (a later
     * check-in stops it being closable). A row without any hours holds a check-in back while it is closable.
     */
    private function blockingOpenAttendance(User $user): ?Attendance
    {
        $open = $this->closableAttendance($user);

        if (! $open) {
            return null;
        }

        $now = now()->startOfSecond();

        if ($this->crossesMidnight($user, $open) && $now->lessThanOrEqualTo($open->check_in->copy()->addHours(self::OVERNIGHT_OPEN_HOURS))) {
            return $open;
        }

        $end = app(SessionResolver::class)->windowOf($open)['end'] ?? null;

        return $end === null || $now->lessThan($end) ? $open : null;
    }

    /**
     * Why an open row can no longer be checked out live: its check-out was refused; or it is an earlier
     * session of today that a later check-in left behind; or (the only or latest session of its day, or a
     * row of an earlier day) its check-out was simply missed. Each is filed as a correction.
     */
    public function notClosableMessage(Attendance $attendance): string
    {
        return match (true) {
            (bool) $attendance->check_out_rejected_at => self::CHECK_OUT_REFUSED_MESSAGE,
            $attendance->date?->isToday() && $this->hasLaterCheckIn($attendance) => self::FORGOTTEN_EARLIER_SESSION_MESSAGE,
            default => self::FORGOTTEN_CHECK_OUT_MESSAGE,
        };
    }

    /**
     * Whether the worker checked in (not rejected) after this row's check-in: the row is then an earlier
     * session of the day, not the only or latest one.
     */
    private function hasLaterCheckIn(Attendance $attendance): bool
    {
        $worker = $attendance->getWorkerProfile();

        return $worker !== null && $attendance->check_in !== null && Attendance::query()
            ->where($this->workSchedule->workerColumn($worker), $worker->getKey())
            ->whereKeyNot($attendance->getKey())
            ->where('check_in', '>', $attendance->check_in)
            ->where(fn ($notRejected) => $notRejected->whereNull('status')->orWhere('status', '!=', AttendanceStatus::Rejected->value))
            ->exists();
    }

    /**
     * An open row of the last {@see self::FORGOTTEN_LOOKBACK_DAYS} days that can no longer be checked out
     * live: its real end time goes through a correction, so a check-out tapped now is never recorded as the
     * end of that shift.
     */
    private function forgottenCheckOut(User $user): ?Attendance
    {
        return Attendance::byWorker($user)
            ->with('shift')
            ->whereDate('date', '>=', today()->subDays(self::FORGOTTEN_LOOKBACK_DAYS))
            ->whereDate('date', '<=', today())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->orderByDesc('check_in')
            ->get()
            ->first(fn (Attendance $row): bool => ! $this->isLiveClosable($row));
    }

    /**
     * Hours of an off-schedule check-in ("Masuk di luar jadwal"): those of the covered shift, which every such
     * check-in must name (window, lateness and early leave apply). The outlet is the one the check-in names,
     * else the one the replaced colleague was rostered at; without either the caller matches the geofence.
     *
     * @return array{0: array<string, mixed>, 1: array{reason: string, replaced_worker_type: string|null, replaced_worker_id: int|null, replaced_schedule_id: int|null}}
     *
     * @throws AttendanceRuleException
     */
    private function offScheduleHours(Employee|Intern|Freelancer $profile, CarbonInterface $businessDate, AttendanceCheckInput $input): array
    {
        $coverShift = $this->offSchedule()->coverShift($profile, $input->coverShiftId);
        $outlet = $this->offSchedule()->coverLocation($profile, $input->coverLocationId);
        $replaced = $this->offSchedule()->replacedWorker($profile, $input->replacesWorker, $businessDate, $coverShift);
        $location = $outlet ?? $this->offSchedule()->outletOf($replaced);

        $hours = $this->workSchedule->hoursForShift($coverShift, $profile, 1, $location);

        return [$hours, [
            'reason' => $this->offSchedule()->reason($replaced['worker'] ?? null),
            'replaced_worker_type' => $replaced ? $this->offSchedule()->typeOf($replaced['worker']) : null,
            'replaced_worker_id' => $replaced ? (int) $replaced['worker']->getKey() : null,
            'replaced_schedule_id' => $replaced ? (int) $replaced['schedule']->getKey() : null,
        ]];
    }

    /**
     * Yesterday's date for an off-schedule check-in after midnight that covers a night shift which started
     * yesterday evening (00:30 on a 22:00 - 06:00 cover shift), when yesterday was not a rostered working
     * day either and nothing was checked in yesterday. Null otherwise.
     */
    private function overnightCoverDate(User $user, Employee|Intern|Freelancer $profile, ?int $coverShiftId): ?CarbonInterface
    {
        if ($coverShiftId === null) {
            return null;
        }

        $shift = Shift::query()->active()->whereKey($coverShiftId)->where('company_id', $profile->company_id)->first();

        if (! $shift || ! $shift->crossesMidnight()) {
            return null;
        }

        $time = now()->format('H:i');

        if ($time >= substr((string) $shift->end_time, 0, 5) || $time >= substr((string) $shift->start_time, 0, 5)) {
            return null;
        }

        $yesterday = today()->subDay();

        if (app(SessionResolver::class)->sessionsFor($profile, $yesterday) !== []) {
            return null;
        }

        $checkedInYesterday = Attendance::byWorker($user)->whereDate('date', $yesterday)->whereNotNull('check_in')->exists();

        return $checkedInYesterday ? null : $yesterday;
    }

    /**
     * The date an off-schedule check-in now would be booked to, given the shift it covers: yesterday for a
     * night shift covered after midnight (see {@see self::overnightCoverDate()}), otherwise today. The cover
     * options list the colleagues rostered on that date, the ones the check-in validates against.
     *
     * @throws AttendanceRuleException
     */
    public function coverBusinessDate(User $user, ?int $coverShiftId): CarbonInterface
    {
        return $this->overnightCoverDate($user, $this->workerProfile($user), $coverShiftId) ?? today();
    }

    private function offSchedule(): OffScheduleCheckInService
    {
        return app(OffScheduleCheckInService::class);
    }

    /**
     * The row's scheduled hours end the next day: those of its schedule snapshot, else of its shift or,
     * without a shift, the company hours (for example 22:00 - 06:00).
     */
    private function crossesMidnight(User $user, Attendance $attendance): bool
    {
        // The hours kept on the row at check-in decide; a row without them is read from its shift as before.
        if ($attendance->hasSnapshot() && $attendance->sched_start_at && $attendance->sched_end_at) {
            return ! $attendance->sched_end_at->isSameDay($attendance->sched_start_at);
        }

        if ($attendance->shift) {
            $shift = $attendance->shift;

            // The second segment of a split shift may end after midnight (for example 18:00 - 00:30).
            if ((int) $attendance->segment === 2 && $shift->hasSecondSegment()) {
                return substr((string) $shift->second_end_time, 0, 5) <= substr((string) $shift->second_start_time, 0, 5);
            }

            return $shift->crossesMidnight();
        }

        $policy = ($user->employee ?? $user->intern ?? $user->freelancer)?->company?->policy;

        if (! $policy?->work_start_time || ! $policy->work_end_time) {
            return false;
        }

        return substr((string) $policy->work_end_time, 0, 5) <= substr((string) $policy->work_start_time, 0, 5);
    }

    /**
     * The worker profile of the user, allowed to work today.
     *
     * @throws AttendanceRuleException
     */
    private function profileOf(User $user): Employee|Intern|Freelancer
    {
        $profile = $this->workerProfile($user);
        $this->ensureActive($profile, today());

        return $profile;
    }

    /**
     * @throws AttendanceRuleException
     */
    private function workerProfile(User $user): Employee|Intern|Freelancer
    {
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            throw new AttendanceRuleException('Profil pengguna tidak ditemukan.', 422);
        }

        return $profile;
    }

    /**
     * Refuse a worker who may not work on the date (nor on any of the other dates given), with the specific
     * reason for the first one (for example a contract that only starts later, "Kontrak Anda baru mulai ...",
     * or an ended one).
     *
     * @throws AttendanceRuleException
     */
    private function ensureActive(Employee|Intern|Freelancer $profile, CarbonInterface $workDate, CarbonInterface ...$otherDates): void
    {
        $accounts = app(WorkerAccountService::class);
        $reason = $accounts->inactiveReason($profile, $workDate);

        if ($reason === null) {
            return;
        }

        foreach ($otherDates as $date) {
            if ($accounts->inactiveReason($profile, $date) === null) {
                return;
            }
        }

        throw new AttendanceRuleException($reason, 403);
    }

    /**
     * Apply the location and selfie rules of the worker's company policy. With a scheduled outlet,
     * the geofence only accepts that outlet.
     *
     * @return array{
     *     latitude: float|null,
     *     longitude: float|null,
     *     photo_path: string|null,
     *     is_out_of_bounds: bool,
     *     is_mock_location: bool,
     *     is_face_verified: bool|null,
     *     face_match_score: int|null,
     *     face_verification_notes: string|null,
     *     liveness_verified: bool|null,
     *     liveness_notes: string|null,
     *     liveness_frames: list<string>|null,
     *     matched_location_id: int|null,
     *     approval_reasons: list<string>,
     * }
     *
     * @throws AttendanceRuleException
     */
    private function runChecks(User $user, Employee|Intern|Freelancer $profile, AttendanceCheckInput $input, string $purpose, ?CompanyLocation $outlet = null): array
    {
        $company = $profile->company;
        $policy = $company?->policy;
        $reasons = [];

        $checks = [
            'latitude' => $input->latitude,
            'longitude' => $input->longitude,
            'photo_path' => $input->photoPath,
            'is_out_of_bounds' => false,
            'is_mock_location' => $input->isMocked,
            'is_face_verified' => null,
            'face_match_score' => null,
            'face_verification_notes' => null,
            'liveness_verified' => null,
            'liveness_notes' => null,
            'liveness_frames' => null,
            'matched_location_id' => null,
        ];

        if ($policy?->require_gps) {
            if ($input->latitude === null || $input->longitude === null) {
                throw new AttendanceRuleException('Lokasi GPS wajib diisi.', 422, 'latitude');
            }

            if ($input->isMocked) {
                if ($policy->mock_location_action !== 'approval') {
                    throw new AttendanceRuleException(
                        'Lokasi palsu (mock location) terdeteksi. Matikan aplikasi pemalsu lokasi lalu coba lagi.',
                        422,
                        'latitude',
                    );
                }

                $reasons[] = 'lokasi palsu terdeteksi';
            }

            $geofence = GeofenceService::validateCompanyGeofence($company, $input->latitude, $input->longitude, $outlet);
            $checks['matched_location_id'] = $geofence['matched_location_id'] ?? null;

            if (! $geofence['is_valid']) {
                $checks['is_out_of_bounds'] = true;
                $reasons[] = match (true) {
                    $geofence['message'] === GeofenceService::NO_LOCATION_CONFIGURED_MESSAGE => 'lokasi absen belum diatur',
                    $outlet !== null => "di luar area outlet jadwal ({$outlet->name})",
                    default => 'di luar area kantor',
                };
            }
        }

        $selfieMode = $policy?->selfieMode() ?? 'none';

        $masterPhoto = $profile->master_face_photo;
        $submittedPaths = array_filter([$input->photoPath, ...$input->livenessFramePaths]);

        if (filled($masterPhoto) && in_array($masterPhoto, $submittedPaths, true)) {
            throw new AttendanceRuleException(SelfieUploadToken::INVALID_MESSAGE, 422, 'photo');
        }

        if (in_array($selfieMode, ['photo', 'face'], true) && blank($input->photoPath)) {
            throw new AttendanceRuleException('Foto selfie absensi wajib diunggah.', 422, 'photo');
        }

        if (! in_array($selfieMode, ['face', 'liveness'], true)) {
            return [...$checks, 'approval_reasons' => $reasons];
        }

        if (blank($profile->master_face_photo)) {
            throw new AttendanceRuleException(
                'Foto profil belum dipasang. Pasang foto profil berisi wajah Anda di menu Profil terlebih dahulu, karena foto itu dipakai untuk verifikasi wajah.',
                422,
                'photo',
            );
        }

        try {
            if ($selfieMode === 'liveness') {
                $faceMatch = $this->verifyLiveness($user, $profile, $policy, $input, $purpose, $checks);
            } else {
                $faceMatch = $this->faceVerifier()->verify($input->photoPath, $profile->master_face_photo, $this->threshold($policy));
            }
        } catch (FaceServiceUnavailable) {
            throw new AttendanceRuleException('Layanan verifikasi wajah sedang tidak tersedia. Coba lagi beberapa saat.', 503, 'photo');
        }

        $checks['is_face_verified'] = $faceMatch->matched;
        $checks['face_match_score'] = $faceMatch->score;
        $checks['face_verification_notes'] = $faceMatch->notes;

        if (! $faceMatch->matched) {
            if ($policy->face_fail_action !== 'approval') {
                throw new AttendanceRuleException('Verifikasi wajah gagal: '.$faceMatch->notes, 422, 'photo');
            }

            $reasons[] = 'verifikasi wajah tidak cocok';
        }

        return [...$checks, 'approval_reasons' => $reasons];
    }

    /**
     * Run the liveness challenge and return the face match of its front frame.
     *
     * The front frame becomes the attendance photo; the liveness fields are written into $checks.
     *
     * @param  array<string, mixed>  $checks
     *
     * @throws AttendanceRuleException
     * @throws FaceServiceUnavailable
     */
    private function verifyLiveness(
        User $user,
        Employee|Intern|Freelancer $profile,
        CompanyPolicy $policy,
        AttendanceCheckInput $input,
        string $purpose,
        array &$checks,
    ): FaceMatch {
        if (blank($input->livenessChallengeId) || $input->livenessFramePaths === []) {
            throw new AttendanceRuleException(
                'Verifikasi liveness wajib. Ambil foto sesuai langkah yang diminta.',
                422,
                'liveness_frames',
            );
        }

        $result = app(LivenessService::class)->verify(
            $user,
            $input->livenessChallengeId,
            $purpose,
            array_values($input->livenessFramePaths),
            $profile->master_face_photo,
            $policy,
        );

        if (! $result->livenessPassed) {
            throw new AttendanceRuleException($result->notes, 422, 'liveness_frames');
        }

        $checks['photo_path'] = $result->frontFramePath;
        $checks['liveness_verified'] = true;
        $checks['liveness_notes'] = $result->notes;
        $checks['liveness_frames'] = array_values($input->livenessFramePaths);

        return $result->faceMatch
            ?? $this->faceVerifier()->verify($result->frontFramePath, $profile->master_face_photo, $this->threshold($policy));
    }

    private function faceVerifier(): FaceVerifier
    {
        return app(FaceVerifier::class);
    }

    private function threshold(CompanyPolicy $policy): int
    {
        return $policy->face_match_threshold ?? self::DEFAULT_FACE_THRESHOLD;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function message(string $action, array $reasons): string
    {
        if ($reasons === []) {
            return "{$action} berhasil";
        }

        return "{$action} berhasil dicatat dan sedang menunggu persetujuan atasan karena ".implode(' dan ', $reasons).'.';
    }
}
