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
use App\Services\Face\FaceMatch;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;
use App\Support\SelfieUploadToken;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** The roster of the date changed between reading it and saving the check-in (for example a replacement approved meanwhile). */
    public const ROSTER_CHANGED_MESSAGE = 'Jadwal Anda hari ini baru saja berubah. Muat ulang lalu coba absen lagi.';

    /**
     * A rejected attendance ends there: a check-out would open a new approval round whose approval
     * turns the rejected day into a valid one.
     */
    public const REJECTED_CHECK_OUT_MESSAGE = 'Presensi ini sudah ditolak atasan, jadi tidak bisa check out. Ajukan koreksi presensi bila ada kekeliruan.';

    /** An overnight row of yesterday counts as still open for this many hours after its check-in. */
    public const OVERNIGHT_OPEN_HOURS = 20;

    /** The second segment of a split shift accepts check-in from this many minutes before it starts. */
    private const SECOND_SEGMENT_OPENS_MINUTES_BEFORE = 60;

    /** How long before the shift (or company hours) starts the first check-in of the day opens. */
    private const CHECK_IN_OPENS_MINUTES_BEFORE = 60;

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
        $businessDate = $this->overnightSecondSegmentDate($user, $profile)
            ?? $this->overnightCheckInDate($user, $profile)
            ?? $this->earlyNextDayCheckInDate($user, $profile)
            ?? $this->overnightCoverDate($user, $profile, $input->coverShiftId)
            ?? today();
        // Judged on the day the shift belongs to: a night shift starting on the first contract day is allowed.
        $this->ensureActive($profile, $businessDate);
        $todayRows = Attendance::byWorker($user)->whereDate('date', $businessDate)->orderBy('segment')->get();
        $existingAttendance = $todayRows->firstWhere('segment', 1);

        // An approved leave writes today's row without a check-in; checking in would overwrite the leave.
        if ($this->isApprovedLeave($existingAttendance)) {
            throw new AttendanceRuleException(self::ON_LEAVE_MESSAGE, 409);
        }

        // Last night's shift is still open after midnight: it is checked out first, not doubled by a new day.
        if (! $existingAttendance?->check_in && $this->openOvernightAttendance($user)) {
            throw new AttendanceRuleException(self::OPEN_OVERNIGHT_MESSAGE, 409);
        }

        $segment = 1;
        $workingHours = $this->workSchedule->resolve($profile, $businessDate);
        /** @var array{reason: string, replaced_worker_type: string|null, replaced_worker_id: int|null}|null $offSchedule */
        $offSchedule = null;

        if ($existingAttendance?->check_in) {
            // A split shift has a second segment to check in to once the first one is checked out.
            $second = $todayRows->firstWhere('segment', 2);
            $first = $existingAttendance;

            // A rejected first segment can no longer be checked out, so it does not hold the second one back.
            $firstSegmentClosed = $first->check_out || $first->status === AttendanceStatus::Rejected;

            // The first segment covered a split shift outside the roster, and the roster still has no shift:
            // the second segment follows that cover shift (and waits for approval the same way).
            $coverShift = $first->is_off_schedule && $this->workSchedule->isOffSchedule($profile, $workingHours) ? $first->shift : null;
            $segmentCount = $coverShift ? ($coverShift->hasSecondSegment() ? 2 : 1) : $workingHours['segment_count'];

            if ($segmentCount < 2 || ! $firstSegmentClosed || $second?->check_in) {
                throw new AttendanceRuleException(self::ALREADY_CHECKED_IN_MESSAGE, 409);
            }

            $segment = 2;
            $existingAttendance = $second;

            if ($coverShift) {
                $workingHours = $this->workSchedule->hoursForShift($coverShift, $profile, 2, $first->location);
                $replaced = $first->replaced_worker_type && $first->replaced_worker_id
                    ? $this->offSchedule()->workerFromKey($first->replaced_worker_type.':'.$first->replaced_worker_id)
                    : null;
                $offSchedule = [
                    'reason' => $this->offSchedule()->reason($replaced),
                    'replaced_worker_type' => $first->replaced_worker_type,
                    'replaced_worker_id' => $first->replaced_worker_id,
                ];
            } else {
                $workingHours = $this->workSchedule->resolve($profile, $businessDate, 2);
            }

            // Checking in hours before the second segment starts would only record a presence nobody worked.
            $opensAt = $businessDate->copy()->setTimeFromTimeString((string) $workingHours['start_time'])->subMinutes(self::SECOND_SEGMENT_OPENS_MINUTES_BEFORE);

            if (now()->lessThan($opensAt)) {
                throw new AttendanceRuleException(
                    sprintf('Segmen kedua dimulai pukul %s. Check in dibuka pukul %s.', substr((string) $workingHours['start_time'], 0, 5), $opensAt->format('H:i')),
                    422,
                );
            }
        }

        if ($segment === 1 && $this->workSchedule->isOffSchedule($profile, $workingHours)) {
            [$workingHours, $offSchedule] = $this->offScheduleHours($profile, $businessDate, $input, $workingHours);
        }

        // Checking in hours before the shift starts would close the day as "on time" for a presence nobody
        // worked (and block the real check-in later): it opens a fixed time before the start, as segment 2 does.
        if ($segment === 1 && ! $workingHours['is_day_off']) {
            $opensAt = $businessDate->copy()->setTimeFromTimeString((string) $workingHours['start_time'])->subMinutes(self::CHECK_IN_OPENS_MINUTES_BEFORE);

            if (now()->lessThan($opensAt)) {
                throw new AttendanceRuleException(
                    sprintf('%s dimulai pukul %s. Absen masuk dibuka pukul %s.', $workingHours['shift'] ? 'Shift' : 'Jam kerja', substr((string) $workingHours['start_time'], 0, 5), $opensAt->format('H:i')),
                    422,
                );
            }
        }

        $checks = $this->runChecks($user, $profile, $input, self::PURPOSE_CHECK_IN, $workingHours['location']);
        $reasons = $checks['approval_reasons'];

        if ($offSchedule) {
            array_unshift($reasons, $offSchedule['reason']);
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
            $attendance = $this->saveCheckIn($user, $profile, $existingAttendance, $businessDate, $segment, $checks, $workingHours, $now, $status, $lateMinutes, $checkInLateMinutes, $requiresApproval, $offSchedule);
        } catch (UniqueConstraintViolationException) {
            // Another write created today's row first: a leave approved meanwhile, or a second request
            // of the same tap (double submit, retry) that won the race on the unique attendance index.
            $winner = Attendance::byWorker($user)->whereDate('date', $businessDate)->where('segment', $segment)->first();

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
     * @param  array<string, mixed>  $checks
     * @param  array<string, mixed>  $workingHours
     * @param  array{reason: string, replaced_worker_type: string|null, replaced_worker_id: int|null}|null  $offSchedule
     */
    private function saveCheckIn(
        User $user,
        Employee|Intern|Freelancer $profile,
        ?Attendance $existingAttendance,
        CarbonInterface $businessDate,
        int $segment,
        array $checks,
        array $workingHours,
        CarbonInterface $now,
        AttendanceStatus $status,
        int $lateMinutes,
        int $checkInLateMinutes,
        bool $requiresApproval,
        ?array $offSchedule = null,
    ): Attendance {
        return DB::transaction(function () use ($user, $profile, $existingAttendance, $businessDate, $segment, $checks, $workingHours, $now, $status, $lateMinutes, $checkInLateMinutes, $requiresApproval, $offSchedule): Attendance {
            // The roster row is read again under a lock, the same one an approved replacement takes before it
            // gives this worker the day off (OffScheduleCheckInService::applyApproval()): a check-in judged as a
            // rostered day is refused when that day off, or another shift or outlet, landed in between, instead
            // of being stored against hours the roster no longer has.
            if ($segment === 1 && $offSchedule === null) {
                $roster = ShiftSchedule::query()
                    ->where($this->workSchedule->workerColumn($profile), $profile->getKey())
                    ->whereDate('date', $businessDate->toDateString())
                    ->lockForUpdate()
                    ->first();

                if ($roster?->is_day_off || $this->rosterDiffersFrom($profile, $roster, $workingHours)) {
                    throw new AttendanceRuleException(self::ROSTER_CHANGED_MESSAGE, 409);
                }
            }

            // A colleague is replaced by one backup: checked again under the colleague's roster lock.
            if ($offSchedule !== null && $offSchedule['replaced_worker_type'] !== null && $offSchedule['replaced_worker_id'] !== null) {
                $this->offSchedule()->lockReplacement($offSchedule['replaced_worker_type'], $offSchedule['replaced_worker_id'], $businessDate, $profile);
            }

            // Reuse today's row (for example one created by an approved correction) instead of
            // inserting a second row, which the unique (worker, date, segment) index rejects. An update
            // never trips that index, so the row is read again under a lock: a concurrent check-in
            // (double tap, retry) or a leave approved meanwhile is refused instead of overwritten.
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
            ]);

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
     * Whether the (locked) roster row of the date names another shift or outlet than the hours the check-in
     * was judged against, which were resolved before the lock.
     *
     * @param  array<string, mixed>  $workingHours
     */
    private function rosterDiffersFrom(Employee|Intern|Freelancer $profile, ?ShiftSchedule $roster, array $workingHours): bool
    {
        $rosterShift = $roster?->shift ?? ($profile->shift_id ? $profile->shift : null);

        if ((int) $rosterShift?->getKey() !== (int) $workingHours['shift']?->getKey()) {
            return true;
        }

        return $roster !== null && (int) $roster->company_location_id !== (int) $workingHours['location']?->getKey();
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
            // Yesterday's day shift left open: tapping now would record tonight as its end time.
            if ($this->openRowOfYesterday($user)) {
                throw new AttendanceRuleException(self::FORGOTTEN_CHECK_OUT_MESSAGE, 422);
            }

            throw new AttendanceRuleException('Anda belum melakukan check in hari ini.', 422);
        }

        if ($attendance->check_out) {
            throw new AttendanceRuleException(self::ALREADY_CHECKED_OUT_MESSAGE, 409);
        }

        if ($attendance->status === AttendanceStatus::Rejected) {
            throw new AttendanceRuleException(self::REJECTED_CHECK_OUT_MESSAGE, 409);
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
     * leave request of the same worker that is still approved and still covers the row's date. Nothing reverts
     * the rows of a leave that is deleted or moved later, so such an orphaned row is checked in over as usual.
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
            ->exists();
    }

    /**
     * Today's open row (latest segment), or yesterday's still-open row when its shift crosses midnight.
     * A forgotten check-out of yesterday's day shift is not offered: its real end time goes through a
     * correction, so a check-out tapped the next night is never recorded as the end of that shift.
     */
    private function attendanceToCheckOut(User $user): ?Attendance
    {
        $todayRows = Attendance::byWorker($user)->with('location')->today()->orderByDesc('segment')->get();
        $today = $todayRows->first();

        if ($today?->check_in) {
            // The open segment of a split shift, else the latest one (which is then refused as already checked out).
            return $todayRows->first(fn (Attendance $row): bool => $row->check_in !== null && $row->check_out === null) ?? $today;
        }

        return $this->openOvernightAttendance($user) ?? $today;
    }

    /**
     * Yesterday's row of an overnight shift (its hours cross midnight) that is still open after midnight.
     * Until it is checked out, the app offers its check-out and a new check-in is refused.
     *
     * A day shift whose check-out was forgotten does not count: that worker checks in to today as usual.
     */
    public function openOvernightAttendance(User $user): ?Attendance
    {
        $open = $this->openRowOfYesterday($user);

        return $open && $this->crossesMidnight($user, $open) ? $open : null;
    }

    /**
     * Yesterday's row checked in within the last {@see self::OVERNIGHT_OPEN_HOURS} hours and not checked out yet.
     */
    private function openRowOfYesterday(User $user): ?Attendance
    {
        return Attendance::byWorker($user)
            ->with(['location', 'shift'])
            ->whereDate('date', today()->subDay())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('check_in', '>=', now()->subHours(self::OVERNIGHT_OPEN_HOURS))
            ->orderByDesc('segment')
            ->first();
    }

    /**
     * Yesterday's date when this check-in is the first one of a night shift that started yesterday
     * evening (for example 00:30 on a 22:00 - 06:00 shift, nothing checked in since): the shift belongs
     * to the date it starts on, its lateness counts from yesterday's start, and the worker's own night
     * shift starting this evening stays free. Null for a normal check-in on today's date.
     */
    private function overnightCheckInDate(User $user, Employee|Intern|Freelancer $profile): ?CarbonInterface
    {
        $yesterday = today()->subDay();
        $hours = $this->workSchedule->resolve($profile, $yesterday);
        $start = substr((string) $hours['start_time'], 0, 5);
        $end = substr((string) $hours['end_time'], 0, 5);

        if ($hours['is_day_off'] || $end > $start || now()->format('H:i') >= $end) {
            return null;
        }

        // A rotating roster: today's own shift (for example 06:00 - 14:00 after last night's 22:00 - 06:00)
        // is nearer than last night's start, so this is an early check-in to today, not a late one to yesterday.
        $todayHours = $this->workSchedule->resolve($profile, today());

        if (! $todayHours['is_day_off']) {
            $todayStart = today()->setTimeFromTimeString((string) $todayHours['start_time']);
            $yesterdayStart = $yesterday->copy()->setTimeFromTimeString((string) $hours['start_time']);

            if (abs(now()->diffInMinutes($todayStart)) < abs(now()->diffInMinutes($yesterdayStart))) {
                return null;
            }
        }

        $rows = Attendance::byWorker($user)
            ->where(fn ($query) => $query->whereDate('date', $yesterday)->orWhereDate('date', today()))
            ->get();

        if ($rows->contains(fn (Attendance $row): bool => $row->check_in !== null)) {
            return null;
        }

        $yesterdayRow = $rows->first(fn (Attendance $row): bool => $row->date->isSameDay($yesterday) && (int) $row->segment === 1);

        return $this->isApprovedLeave($yesterdayRow) ? null : $yesterday;
    }

    /**
     * Yesterday's date for a check-in after midnight to the second segment of yesterday's split shift that
     * runs past midnight (00:10 on 16:00 - 20:00 & 22:00 - 02:00), while its first segment is closed and the
     * second one is not checked in yet: the existing second-segment rules then apply to yesterday's rows.
     */
    private function overnightSecondSegmentDate(User $user, Employee|Intern|Freelancer $profile): ?CarbonInterface
    {
        $yesterday = today()->subDay();
        $hours = $this->workSchedule->resolve($profile, $yesterday, 2);

        if ($hours['is_day_off'] || $hours['segment'] !== 2) {
            return null;
        }

        $start = substr((string) $hours['start_time'], 0, 5);
        $end = substr((string) $hours['end_time'], 0, 5);

        if ($end > $start || now()->format('H:i') >= $end) {
            return null;
        }

        $rows = Attendance::byWorker($user)->whereDate('date', $yesterday)->get();
        $first = $rows->first(fn (Attendance $row): bool => (int) $row->segment === 1);
        $second = $rows->first(fn (Attendance $row): bool => (int) $row->segment === 2);

        if (! $first?->check_in || ! ($first->check_out || $first->status === AttendanceStatus::Rejected) || $second?->check_in) {
            return null;
        }

        return $yesterday;
    }

    /**
     * Tomorrow's date for an early check-in before midnight to a shift starting shortly after it (23:59 on a
     * 00:00 - 08:00 shift, whose check-in opens at 23:00): the shift belongs to the date it starts on and is
     * judged against that start. Null when today's own shift is nearer, or nothing of tomorrow is open yet.
     */
    private function earlyNextDayCheckInDate(User $user, Employee|Intern|Freelancer $profile): ?CarbonInterface
    {
        $tomorrow = today()->addDay();
        $hours = $this->workSchedule->resolve($profile, $tomorrow);

        if ($hours['is_day_off'] || $this->workSchedule->isOffSchedule($profile, $hours)) {
            return null;
        }

        $tomorrowStart = $tomorrow->copy()->setTimeFromTimeString((string) $hours['start_time']);

        if (now()->lessThan($tomorrowStart->copy()->subMinutes(self::CHECK_IN_OPENS_MINUTES_BEFORE))) {
            return null;
        }

        $todayHours = $this->workSchedule->resolve($profile, today());

        if (! $todayHours['is_day_off']) {
            $todayStart = today()->setTimeFromTimeString((string) $todayHours['start_time']);

            if (abs(now()->diffInMinutes($todayStart)) < abs(now()->diffInMinutes($tomorrowStart))) {
                return null;
            }
        }

        // Today's shift is still open: it is checked out first.
        $openToday = Attendance::byWorker($user)
            ->whereDate('date', today())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->exists();

        return $openToday ? null : $tomorrow;
    }

    /**
     * Hours of an off-schedule check-in ("Masuk di luar jadwal"): those of the covered shift, which every such
     * check-in must name (window, lateness and early leave apply). The outlet is the one the replaced
     * colleague was rostered at, if any.
     *
     * @param  array<string, mixed>  $rosterHours
     * @return array{0: array<string, mixed>, 1: array{reason: string, replaced_worker_type: string|null, replaced_worker_id: int|null}}
     *
     * @throws AttendanceRuleException
     */
    private function offScheduleHours(Employee|Intern|Freelancer $profile, CarbonInterface $businessDate, AttendanceCheckInput $input, array $rosterHours): array
    {
        $coverShift = $this->offSchedule()->coverShift($profile, $input->coverShiftId);
        $replaced = $this->offSchedule()->replacedWorker($profile, $input->replacesWorker, $businessDate, $coverShift);
        $location = $this->offSchedule()->outletOf($replaced) ?? $rosterHours['location'];

        $hours = $this->workSchedule->hoursForShift($coverShift, $profile, 1, $location);

        return [$hours, [
            'reason' => $this->offSchedule()->reason($replaced['worker'] ?? null),
            'replaced_worker_type' => $replaced ? $this->offSchedule()->typeOf($replaced['worker']) : null,
            'replaced_worker_id' => $replaced ? (int) $replaced['worker']->getKey() : null,
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

        if (! $this->workSchedule->isOffSchedule($profile, $this->workSchedule->resolve($profile, $yesterday))) {
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
     * The row's shift ends the next day, or, without a shift, the company hours do (for example 22:00 - 06:00).
     */
    private function crossesMidnight(User $user, Attendance $attendance): bool
    {
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
                'Foto Master Wajah belum didaftarkan. Silakan daftarkan Foto Master Wajah Anda terlebih dahulu.',
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
