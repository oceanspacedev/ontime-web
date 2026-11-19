<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\Attendance\WorkSession;
use App\Services\AttendanceCheckService;
use App\Services\AttendanceLatenessService;
use App\Services\OffScheduleCheckInService;
use App\Services\SessionResolver;
use App\Services\ShiftAssignmentService;
use App\Services\WorkScheduleService;
use App\Traits\ScopesToWorkerVisibility;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A request to change a recorded attendance: its times, and (a schedule correction) the shift or outlet that
 * really applied. It is the controlled way to change a result that is locked to its schedule (D4).
 *
 * The attendance row it writes (its target: schedule number and part, and the row when it exists) is
 * resolved when the correction is submitted and stored with it ({@see self::resolveTarget()}), so the
 * approval writes exactly what the worker meant. A correction never creates a row without a check-in.
 *
 * origin "admin" is the record of an admin's edit or deletion of an attendance on the panel, approved by
 * that admin at once ({@see self::recordAdminChange()}); it is history, never a claim on a time.
 */
class AttendanceCorrection extends Model
{
    use HasFactory, ScopesToWorkerVisibility;

    public const ORIGIN_WORKER = 'worker';

    public const ORIGIN_ADMIN = 'admin';

    protected $fillable = [
        'employee_id',
        'intern_id',
        'freelancer_id',
        'attendance_id',
        'date',
        'corrected_check_in',
        'corrected_check_out',
        'reason',
        'attachment',
        'status',
        'current_step',
        'rejection_reason',
        'shift_schedule_id',
        'target_sequence',
        'target_segment',
        'check_out_next_day',
        'corrected_shift_id',
        'corrected_company_location_id',
        'origin',
        'before_values',
    ];

    /**
     * Formats accepted for a corrected time: the "HH:MM" the mobile app and the panel send, or a
     * full datetime from older API clients. The time is anchored on the corrected date; a check-out given
     * with the next day's date is read as the next day ({@see self::$check_out_next_day}).
     */
    public const TIME_INPUT_FORMATS = 'H:i,H:i:s,Y-m-d H:i,Y-m-d H:i:s';

    /**
     * Shown when a worker submits a correction for a date and segment that already has a pending
     * or approved correction: a second one would silently overwrite the first once approved.
     */
    public const ADMIN_FILED_MESSAGE = 'Pengajuan ini dibuat admin, tidak bisa diubah atau dibatalkan dari aplikasi.';

    public const DUPLICATE_MESSAGE = 'Sudah ada pengajuan koreksi yang menunggu persetujuan atau sudah disetujui untuk jam yang sama pada tanggal ini. Batalkan atau ubah pengajuan tersebut, jangan mengajukan ulang.';

    /**
     * How long before the segment start a corrected check-in may lie, matching the window in which
     * the app accepts a check-in.
     */
    public const CHECK_IN_EARLIEST_MINUTES_BEFORE = 60;

    /**
     * Shown when the date has several schedules and the corrected time does not tell which one is meant.
     */
    public const AMBIGUOUS_TARGET_MESSAGE = 'Tanggal ini punya lebih dari satu jadwal. Pilih jadwal yang dikoreksi.';

    /**
     * Shown when a correction would give a check-out (or a schedule) to a session that has no check-in.
     */
    public const CHECK_IN_REQUIRED_MESSAGE = 'Belum ada jam masuk pada jadwal ini. Isi juga jam masuk yang benar, bukan hanya jam pulang.';

    /**
     * Shown when a correction names a schedule or an attendance that is not the worker's on that date.
     */
    public const UNKNOWN_TARGET_MESSAGE = 'Jadwal atau absensi yang dipilih tidak ditemukan pada tanggal ini.';

    /**
     * The schedule a correction was filed against is gone from its date (cleared, or replaced by another).
     */
    public const SCHEDULE_GONE_MESSAGE = 'Jadwal yang dikoreksi sudah tidak ada pada tanggal tersebut.';

    /**
     * A schedule correction of one part of a split whose other part was worked on the same schedule.
     */
    public const SPLIT_SIBLING_MESSAGE = 'Jadwal ini juga dipakai absensi bagian lain pada hari itu, sehingga shift atau outletnya tidak bisa diubah lewat koreksi satu bagian saja.';

    /**
     * The date part of a full check-out datetime sent by the client, read once when the value is set; null
     * for a bare time. Not stored: it only tells the next-day check-out of older clients.
     */
    private ?string $checkOutGivenDate = null;

    /**
     * The resolved target, cached per state of the fields it depends on.
     *
     * @var array{key: string, target: array<string, mixed>}|null
     */
    private ?array $targetCache = null;

    protected static function booted(): void
    {
        // The form and API send only a time (e.g. "08:30"), which the datetime cast places on the
        // submission day. Anchor both times on the attendance date being corrected; a check-out after
        // midnight is kept on the next day ({@see self::correctedCheckOutMoment()}). An admin's record keeps
        // the exact times it was given.
        static::saving(function (AttendanceCorrection $correction): void {
            if (! $correction->date || $correction->origin === self::ORIGIN_ADMIN) {
                return;
            }

            if ($correction->exists && ! $correction->isDirty(['date', 'corrected_check_in', 'corrected_check_out', 'check_out_next_day', 'corrected_shift_id', 'attendance_id', 'shift_schedule_id', 'target_sequence', 'target_segment'])) {
                return;
            }

            if ($correction->checkOutGivenDate !== null && $correction->checkOutGivenDate === Carbon::parse($correction->date)->addDay()->toDateString()) {
                $correction->check_out_next_day = true;
            }

            foreach (['corrected_check_in', 'corrected_check_out'] as $column) {
                $time = $correction->getAttribute($column);

                if ($time) {
                    $correction->setAttribute($column, Carbon::parse($correction->date)->setTimeFrom($time));
                }
            }

            $checkOut = $correction->correctedCheckOutMoment();

            if ($checkOut && ! $checkOut->isSameDay(Carbon::parse($correction->date))) {
                $correction->corrected_check_out = $checkOut;
                $correction->check_out_next_day = true;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'corrected_check_in' => 'datetime',
            'corrected_check_out' => 'datetime',
            'current_step' => 'integer',
            'shift_schedule_id' => 'integer',
            'target_sequence' => 'integer',
            'target_segment' => 'integer',
            'check_out_next_day' => 'boolean',
            'corrected_shift_id' => 'integer',
            'corrected_company_location_id' => 'integer',
            'before_values' => 'array',
        ];
    }

    /**
     * Keep the date a full check-out datetime was sent with ({@see self::$checkOutGivenDate}).
     */
    public function setCorrectedCheckOutAttribute(mixed $value): void
    {
        $this->checkOutGivenDate = is_string($value) && preg_match('/^\s*(\d{4}-\d{2}-\d{2})[ T]/', $value, $matches) === 1 ? $matches[1] : null;
        $this->attributes['corrected_check_out'] = blank($value) ? null : $this->fromDateTime($value);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function intern(): BelongsTo
    {
        return $this->belongsTo(Intern::class);
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function correctedShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'corrected_shift_id');
    }

    public function correctedLocation(): BelongsTo
    {
        return $this->belongsTo(CompanyLocation::class, 'corrected_company_location_id');
    }

    public function getWorkerProfile(): mixed
    {
        return $this->employee ?? $this->intern ?? $this->freelancer;
    }

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalRequestStep::class, 'approvable');
    }

    /**
     * Shown when a correction targets an attendance whose out-of-area check-in still waits for the approver.
     */
    public const PENDING_ATTENDANCE_MESSAGE = 'Absensi pada tanggal ini masih menunggu persetujuan atasan (presensi di luar area). Tunggu sampai atasan memprosesnya sebelum mengajukan koreksi.';

    /**
     * Shown when a correction without a check-in targets an attendance the approver refused: only a
     * correction that gives the real check-in time revives it.
     */
    public const REJECTED_ATTENDANCE_MESSAGE = 'Absensi pada tanggal ini sudah ditolak oleh atasan. Bila Anda memang bekerja, ajukan koreksi dengan jam masuk (dan jam pulang) yang sebenarnya.';

    /**
     * Shown when the corrected date is a day of approved leave: it is already counted as leave, so
     * recording worked hours on it would pay the day twice.
     */
    public const ON_LEAVE_MESSAGE = 'Tanggal ini tercatat cuti atau izin yang sudah disetujui, sehingga jam kerjanya tidak bisa dikoreksi. Batalkan cuti/izin tersebut terlebih dahulu bila Anda ternyata masuk kerja.';

    /**
     * Shown when the corrected date has not happened yet.
     */
    public const FUTURE_DATE_MESSAGE = 'Tanggal koreksi tidak boleh melewati hari ini.';

    /**
     * Corrections that claim a change of an attendance: every worker's request and an admin's schedule
     * correction, not the record of an admin's edit or deletion ({@see self::recordAdminChange()}).
     */
    public function scopeClaims(Builder $query): Builder
    {
        return $query->where(fn (Builder $claims) => $claims
            ->where('origin', '!=', self::ORIGIN_ADMIN)
            ->orWhereNull('origin')
            ->orWhereNotNull('corrected_shift_id')
            ->orWhereNotNull('corrected_company_location_id'));
    }

    /**
     * Whether this is the record of an admin's edit or deletion ({@see self::recordAdminChange()}), not a
     * claim: the opposite of {@see self::scopeClaims()}.
     */
    public function isAdminRecord(): bool
    {
        return $this->origin === self::ORIGIN_ADMIN && ! $this->isScheduleCorrection();
    }

    /**
     * Whether this correction changes the shift or the outlet of the day (a schedule correction).
     */
    public function isScheduleCorrection(): bool
    {
        return $this->corrected_shift_id !== null || $this->corrected_company_location_id !== null;
    }

    /**
     * Write the correction onto its attendance and mark it approved.
     *
     * The corrected times are written on the target row (created when it does not exist yet, then with
     * the schedule of its session kept on it, its outlet and schedule link included). The row is judged
     * against the schedule kept on it; a day without any session (an unscheduled day) is never late and
     * is kept as worked off schedule. A schedule correction changes the roster row of the day (the only
     * way to change a locked one), keeps the new schedule on the row and judges it again. A rejected row is
     * revived by a correction that gives its check-in; a row waiting for the geofence approver keeps
     * waiting, with the verdict the approver will apply. What the row held before is kept on the correction.
     *
     * Refused, touching nothing: a correction without any time or schedule, a date that has not happened
     * yet, a day of approved leave, a target that cannot be resolved, a time another approved correction
     * already wrote, a check-out on a session without a check-in, a check-out-only correction of a
     * rejected row, a check-out not after the check-in, and a row created meanwhile by a parallel request.
     *
     * @throws ValidationException
     */
    public function applyCorrection(): void
    {
        if (! $this->corrected_check_in && ! $this->corrected_check_out && ! $this->isScheduleCorrection()) {
            throw ValidationException::withMessages([
                'approve' => 'Pengajuan koreksi ini tidak berisi jam masuk maupun jam pulang, sehingga tidak bisa disetujui. Tolak pengajuan ini dan minta pekerja mengajukan ulang.',
            ]);
        }

        // The corrected times were checked against the clock at submission; a date after today can
        // only come from a row that skipped that check, and must never become a future attendance.
        if (Carbon::parse($this->date)->startOfDay()->greaterThan(today())) {
            throw ValidationException::withMessages([
                'approve' => self::FUTURE_DATE_MESSAGE.' Tolak pengajuan ini.',
            ]);
        }

        // A day of approved leave stays a leave day, as check-in refuses it and leave approval refuses
        // a worked day: otherwise the day would count both as paid leave and as worked.
        if ($this->isOnApprovedLeaveDay()) {
            throw ValidationException::withMessages([
                'approve' => self::ON_LEAVE_MESSAGE.' Tolak pengajuan ini.',
            ]);
        }

        $this->targetCache = null;
        $target = $this->target();

        if ($target['error'] !== []) {
            throw ValidationException::withMessages(['approve' => reset($target['error']).' Tolak pengajuan ini dan minta pekerja mengajukan ulang.']);
        }

        $profile = $this->getWorkerProfile();

        try {
            DB::transaction(function () use ($target, $profile): void {
                $this->writeAttendance($target, $profile);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'approve' => 'Data absensi pekerja untuk tanggal ini baru saja dibuat oleh proses lain. Muat ulang halaman lalu setujui ulang pengajuan ini.',
            ]);
        }
    }

    /**
     * @param  array{attendance: Attendance|null, session: WorkSession|null, schedule_id: int|null, sequence: int, segment: int, error: array<string, string>}  $target
     */
    private function writeAttendance(array $target, Employee|Intern|Freelancer|null $profile): void
    {
        // Locked so a geofence decision on the same row cannot interleave with this write.
        $attendance = Attendance::query()
            ->where($this->workerKey())
            ->when(
                $target['attendance'] !== null,
                fn ($query) => $query->whereKey($target['attendance']->getKey()),
                fn ($query) => $query
                    ->whereDate('date', Carbon::parse($this->date)->toDateString())
                    ->where('schedule_sequence', $target['sequence'])
                    ->where('segment', $target['segment']),
            )
            ->lockForUpdate()
            ->first() ?? new Attendance([
                'date' => Carbon::parse($this->date)->toDateString(),
                ...$this->workerKey(),
                'schedule_sequence' => $target['sequence'],
                'segment' => $target['segment'],
            ]);

        // Another approved correction only blocks this one when both wrote the same time: an approved
        // check-in correction leaves the day's check-out free for a later check-out correction. A schedule
        // correction may supersede an approved one (the only way to fix a wrong one on a locked roster row);
        // the values it replaces are kept in before_values.
        if ($attendance->exists && ($this->corrected_check_in || $this->corrected_check_out) && static::query()
            ->where('attendance_id', $attendance->id)
            ->where('status', 'approved')
            ->claims()
            ->whereKeyNot($this->getKey())
            ->where(function ($query): void {
                if ($this->corrected_check_in) {
                    $query->orWhereNotNull('corrected_check_in');
                }
                if ($this->corrected_check_out) {
                    $query->orWhereNotNull('corrected_check_out');
                }
            })
            ->exists()) {
            throw ValidationException::withMessages([
                'approve' => 'Jam pada absensi tanggal ini sudah diubah oleh pengajuan koreksi lain yang telah disetujui. Tolak pengajuan ini agar koreksi sebelumnya tidak tertimpa.',
            ]);
        }

        // A correction never creates (or leaves) a session with a check-out but no check-in.
        if (! $this->corrected_check_in && ! $attendance->check_in) {
            throw ValidationException::withMessages(['approve' => self::CHECK_IN_REQUIRED_MESSAGE.' Tolak pengajuan ini dan minta pekerja mengajukan ulang.']);
        }

        $revive = $attendance->status === AttendanceStatus::Rejected;

        if ($revive && ! $this->corrected_check_in) {
            throw ValidationException::withMessages(['approve' => self::REJECTED_ATTENDANCE_MESSAGE.' Tolak pengajuan ini.']);
        }

        $before = $attendance->exists ? $attendance->auditValues() : null;
        $newCheckIn = ! $attendance->exists || $attendance->getOriginal('check_in') === null;

        $checkIn = $this->correctedCheckInMoment();

        if ($checkIn) {
            $attendance->check_in = $checkIn;
        }
        if ($this->corrected_check_out) {
            $attendance->check_out = $this->correctedCheckOutMoment($attendance->check_in);
        }

        // The attendance may have changed since submission, so the resulting pair is checked again.
        if ($attendance->check_in && $attendance->check_out && $attendance->check_out->lessThanOrEqualTo($attendance->check_in)) {
            throw ValidationException::withMessages([
                'approve' => sprintf(
                    'Setelah koreksi ini, jam pulang (%s) tidak setelah jam masuk (%s). Tolak pengajuan ini dan minta pekerja mengajukan ulang dengan jam yang benar.',
                    $attendance->check_out->format('d/m H:i'),
                    $attendance->check_in->format('d/m H:i'),
                ),
            ]);
        }

        if (($checkIn || $this->corrected_check_out) && $attendance->check_in && ($overlap = $this->overlappingAttendance($attendance->check_in, $attendance->check_out, $attendance->exists ? (int) $attendance->getKey() : null))) {
            throw ValidationException::withMessages(['approve' => self::overlapMessage($overlap).' Tolak pengajuan ini.']);
        }

        $attendance->is_corrected = true;

        $rescheduled = $profile !== null && $this->placeOnSchedule($attendance, $target, $profile, $newCheckIn);
        $pendingLateMinutes = null;

        if ($attendance->check_in && ($checkIn || $rescheduled)) {
            $lateMinutes = $attendance->hasSnapshot()
                ? app(WorkScheduleService::class)->snapshotLateMinutes($attendance)
                : app(AttendanceLatenessService::class)->judge($attendance)['late_minutes'];

            if ($attendance->status === AttendanceStatus::PendingApproval) {
                $attendance->check_in_late_minutes = $pendingLateMinutes = $lateMinutes;

                if ($attendance->isCheckOutRound()) {
                    $attendance->rejudgeSettledCheckIn($lateMinutes);
                }
            } else {
                if ($revive) {
                    // Revived: the rejection is kept in the notes, the day counts as worked again.
                    $attendance->notes = trim(($attendance->notes ? $attendance->notes."\n" : '').'Sebelumnya ditolak: '.($attendance->rejection_reason ?: '-').' (dipulihkan lewat koreksi absensi).');
                    $attendance->rejection_reason = null;
                }

                $attendance->late_minutes = $lateMinutes;
                $attendance->status = $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::OnTime;
            }
        }

        // A new schedule kept on the row moves its scheduled end too.
        if ($rescheduled) {
            $attendance->early_leave_minutes = $attendance->check_out ? app(WorkScheduleService::class)->earlyLeaveMinutes($attendance) : null;
        }

        $attendance->save();

        // The saving hook clears the check-in lateness of a moved check-in when the value
        // itself did not change; the geofence approver must still judge the corrected time.
        if ($pendingLateMinutes !== null && $attendance->check_in_late_minutes !== $pendingLateMinutes) {
            $attendance->check_in_late_minutes = $pendingLateMinutes;
            $attendance->saveQuietly();
        }

        $this->update([
            'status' => 'approved',
            'attendance_id' => $attendance->id,
            'before_values' => $before,
            'shift_schedule_id' => $this->shift_schedule_id ?? $attendance->shift_schedule_id,
            'target_sequence' => (int) ($attendance->schedule_sequence ?: 1),
            'target_segment' => (int) ($attendance->segment ?: 1),
        ]);
    }

    /**
     * Keep on the row the schedule it is judged against, without saving it. Returns whether it changed.
     *
     * - A schedule correction changes the roster row of the session (created when the worker worked standing
     *   hours on a day without one) through the roster writer, under the source "correction", and keeps the
     *   corrected schedule on the row. On a day without any session (unscheduled), the row itself takes
     *   the corrected shift (judged against it) and outlet.
     * - A row that already keeps its schedule keeps it (D4).
     * - A row created by this correction, or given its first check-in, keeps the schedule of its session
     *   (outlet and schedule link included); without a session it is worked off schedule: never late
     *   (source "none"), at the outlet the correction names.
     *
     * @param  array{attendance: Attendance|null, session: WorkSession|null, schedule_id: int|null, sequence: int, segment: int}  $target
     */
    private function placeOnSchedule(Attendance $attendance, array $target, Employee|Intern|Freelancer $profile, bool $newCheckIn): bool
    {
        $lateness = app(AttendanceLatenessService::class);
        $session = $target['session'];

        if ($this->isScheduleCorrection()) {
            $scheduleId = $attendance->shift_schedule_id ?: ($session?->scheduleId);
            $schedule = $scheduleId ? ShiftSchedule::query()->find($scheduleId) : null;
            $dayHasRows = ShiftSchedule::query()->where($this->workerKey())->whereDate('date', Carbon::parse($this->date)->toDateString())->exists();

            // Standing hours of a worker not on a roster that day: a new roster row would move the start of
            // their roster to this date ({@see SessionResolver::standingSession()}) and turn every later day
            // to roster rules, so the corrected shift and outlet stay on the row.
            if (! $schedule && $session !== null && ! $dayHasRows && ! $this->isRosteredOnTheDate()) {
                $attendance->shift_id = $this->corrected_shift_id ?? $attendance->shift_id ?? $session->shift?->id;
                $attendance->company_location_id = $this->corrected_company_location_id ?? $attendance->company_location_id ?? $session->location?->id;
                $attendance->shift_schedule_id = null;
                $attendance->schedule_sequence = (int) $target['sequence'];

                if ($attendance->shift_id === null || (int) $attendance->shift_id === (int) $session->shift?->id) {
                    $attendance->fillSnapshot($lateness->snapshotFor($attendance, $session->toWorkingHours()));
                } else {
                    $this->snapshotOwnShift($attendance, $profile, $target);
                }

                return true;
            }

            if ($schedule || ($session !== null && ! $dayHasRows)) {
                $shiftId = $this->corrected_shift_id ?? $schedule?->shift_id ?? $session?->shift?->id ?? $attendance->shift_id;

                if ($shiftId === null) {
                    throw ValidationException::withMessages(['approve' => 'Pilih shift yang sebenarnya berlaku untuk koreksi jadwal ini. Tolak pengajuan ini dan minta pekerja mengajukan ulang.']);
                }

                $locationId = $this->corrected_company_location_id ?? $schedule?->company_location_id ?? $attendance->company_location_id;

                // The other part of a split worked on the same schedule would be left on a plan the schedule no longer has.
                if ($schedule && ((int) $shiftId !== (int) $schedule->shift_id || (int) $locationId !== (int) $schedule->company_location_id) && Attendance::query()
                    ->where('shift_schedule_id', $schedule->getKey())
                    ->when($attendance->exists, fn ($others) => $others->whereKeyNot($attendance->getKey()))
                    ->whereNotNull('check_in')
                    ->where(fn ($notRejected) => $notRejected->whereNull('status')->orWhere('status', '!=', AttendanceStatus::Rejected->value))
                    ->exists()) {
                    throw ValidationException::withMessages(['approve' => self::SPLIT_SIBLING_MESSAGE.' Tolak pengajuan ini.']);
                }

                $schedule = app(ShiftAssignmentService::class)->correctSchedule($profile, Carbon::parse($this->date)->toDateString(), $schedule, (int) $target['sequence'], (int) $shiftId, $locationId === null ? null : (int) $locationId, 'Koreksi absensi: '.mb_substr((string) $this->reason, 0, 200));

                $corrected = collect(app(SessionResolver::class)->sessionsFor($profile, Carbon::parse($this->date)))
                    ->first(fn (WorkSession $candidate): bool => $candidate->scheduleId === (int) $schedule->getKey() && $candidate->segment === (int) ($attendance->segment ?: 1))
                    ?? collect(app(SessionResolver::class)->sessionsFor($profile, Carbon::parse($this->date)))->first(fn (WorkSession $candidate): bool => $candidate->scheduleId === (int) $schedule->getKey());

                $attendance->shift_id = $schedule->shift_id;
                $attendance->company_location_id = $schedule->company_location_id;
                $attendance->fillSnapshot($lateness->snapshotFor($attendance, $corrected?->toWorkingHours()));
                $attendance->schedule_sequence = (int) ($schedule->sequence ?: 1);

                return true;
            }

            if ($this->corrected_shift_id !== null) {
                $attendance->shift_id = $this->corrected_shift_id;
            }

            if ($this->corrected_company_location_id !== null) {
                $attendance->company_location_id = $this->corrected_company_location_id;
            }

            $attendance->is_off_schedule = $attendance->is_off_schedule || $session === null;
            $this->snapshotOwnShift($attendance, $profile, $target);

            return true;
        }

        if ($attendance->hasSnapshot() || (! $newCheckIn && ! $this->corrected_check_in)) {
            return false;
        }

        if ($session !== null && (! $attendance->shift_id || (int) $attendance->shift_id === (int) $session->shift?->id)) {
            $attendance->shift_id ??= $session->shift?->id;
            $attendance->company_location_id ??= $session->location?->id;
            $attendance->fillSnapshot($lateness->snapshotFor($attendance, $session->toWorkingHours()));

            return true;
        }

        if (! $attendance->exists && $session === null) {
            // An unscheduled day (a freelancer, or a roster company's worker without a session): worked off
            // schedule, never late, unless the correction names the shift that was covered.
            $attendance->is_off_schedule = true;
            $attendance->company_location_id ??= $this->corrected_company_location_id;
        }

        $this->snapshotOwnShift($attendance, $profile, $target);

        return true;
    }

    /**
     * Whether the worker is already on a roster on the correction's date: their first roster row
     * ({@see ShiftSchedule::whereRosterRow()}, an approved cover's own row left out) falls on the date or
     * earlier, the rule of {@see SessionResolver}, so a row on this date does not move the start of their roster.
     */
    private function isRosteredOnTheDate(): bool
    {
        $first = ShiftSchedule::whereRosterRow(ShiftSchedule::query())->where($this->workerKey())->min('date');

        return $first !== null && substr((string) $first, 0, 10) <= Carbon::parse($this->date)->toDateString();
    }

    /**
     * Keep on the row the hours of its own shift (a cover shift, or the shift a legacy row was written
     * with), without a roster link; without a shift, no hours at all (source "none").
     *
     * @param  array{sequence: int, segment: int}  $target
     */
    private function snapshotOwnShift(Attendance $attendance, Employee|Intern|Freelancer $profile, array $target): void
    {
        $shift = $attendance->shift_id ? Shift::query()->find($attendance->shift_id) : null;
        $outletName = $attendance->company_location_id ? CompanyLocation::query()->whereKey($attendance->company_location_id)->value('name') : null;

        if ($shift) {
            $hours = app(WorkScheduleService::class)->hoursForShift($shift, $profile, (int) ($attendance->segment ?: $target['segment']));
            $attendance->fillSnapshot(app(AttendanceLatenessService::class)->snapshotFor($attendance, $hours));
        } else {
            $attendance->fillSnapshot(app(SessionResolver::class)->snapshotFor(null, 'none', $outletName));
        }

        $attendance->shift_schedule_id = null;
        $attendance->schedule_sequence = (int) $target['sequence'];
    }

    /**
     * Why this correction cannot be submitted, keyed by the field to show it on, or an empty array:
     * a date or time that has not happened yet, a target that cannot be told (several schedules) or is
     * not the worker's, a shift or outlet the worker cannot name, a check-in far outside the session's
     * hours, a check-out not after the check-in, an attendance still waiting for the geofence approver,
     * a check-out-only correction of a rejected attendance, a day of approved leave, a check-out for a
     * session without a check-in, or another pending or approved correction of the same session and time.
     *
     * @return array<string, string>
     */
    public function submissionErrors(): array
    {
        $this->targetCache = null;

        if (! $this->date) {
            return [];
        }

        if ($error = $this->futureError()) {
            return $error;
        }

        if ($this->workerKey() !== [] && ($error = $this->target()['error']) !== []) {
            return $error;
        }

        if ($error = $this->correctedValuesError()) {
            return $error;
        }

        if ($error = $this->checkInWindowError()) {
            return $error;
        }

        if ($error = $this->orderError()) {
            return $error;
        }

        if ($error = $this->nextDayError()) {
            return $error;
        }

        if ($error = $this->overlapError()) {
            return $error;
        }

        if ($this->workerKey() !== []) {
            $attendance = $this->targetAttendance();
            $status = $attendance?->status;

            if ($status === AttendanceStatus::PendingApproval) {
                return ['date' => self::PENDING_ATTENDANCE_MESSAGE];
            }

            if ($status === AttendanceStatus::Rejected && ! $this->corrected_check_in) {
                return ['date' => self::REJECTED_ATTENDANCE_MESSAGE];
            }

            if ($this->isOnApprovedLeaveDay()) {
                return ['date' => self::ON_LEAVE_MESSAGE];
            }

            if (! $this->corrected_check_in && ! $attendance?->check_in) {
                return ['corrected_check_in' => self::CHECK_IN_REQUIRED_MESSAGE];
            }
        }

        if ($this->conflictingCorrection()) {
            return ['date' => self::DUPLICATE_MESSAGE];
        }

        return [];
    }

    /**
     * Resolve the target from the explicit choice (an attendance, or a schedule and part) or, without
     * one, from the date and times ({@see self::target()}), and store it on the correction. The explicit
     * choice is taken as given; anything stored before is resolved again.
     *
     * @return array<string, string> Why the target cannot be resolved, keyed by field; empty when it was stored.
     */
    public function resolveTarget(?int $attendanceId = null, ?int $scheduleId = null, ?int $segment = null): array
    {
        $this->attendance_id = $attendanceId;
        $this->shift_schedule_id = $scheduleId;
        $this->target_sequence = null;
        $this->target_segment = $scheduleId !== null ? $segment : null;
        $this->targetCache = null;

        if (! $this->date || $this->workerKey() === []) {
            return [];
        }

        $target = $this->target();

        if ($target['error'] !== []) {
            return $target['error'];
        }

        $this->forceFill([
            'attendance_id' => $target['attendance']?->getKey(),
            'shift_schedule_id' => $target['schedule_id'],
            'target_sequence' => $target['sequence'],
            'target_segment' => $target['segment'],
        ]);
        $this->targetCache = null;

        return [];
    }

    /**
     * Resolve the target of a pending correction being changed ($stored is the correction as saved, this
     * one carries the change). An explicit attendance or schedule in the change is taken as given, and a
     * part alone is taken on the stored schedule. Otherwise the target stored at submission is kept while
     * the date and times stay the same (a change of the reason alone never moves it); when the times
     * change the target is inferred again, and the stored choice still settles a time that fits more than
     * one session. A changed date, or a stored target that no longer exists, is inferred again.
     *
     * @return array<string, string> Why the target cannot be resolved, keyed by field; empty when it was stored.
     */
    public function resolveTargetOnUpdate(self $stored, ?int $attendanceId = null, ?int $scheduleId = null, ?int $segment = null): array
    {
        if ($attendanceId !== null || $scheduleId !== null) {
            return $this->resolveTarget($attendanceId, $scheduleId, $segment);
        }

        $sameDate = $stored->date !== null && $this->date !== null
            && Carbon::parse($stored->date)->toDateString() === Carbon::parse($this->date)->toDateString();
        $storedSchedule = $stored->shift_schedule_id ?: null;
        $storedAttendance = $stored->attendance_id ?: null;

        if (! $sameDate || ($storedSchedule === null && $storedAttendance === null)) {
            return $this->resolveTarget();
        }

        if ($segment !== null && $storedSchedule !== null) {
            return $this->resolveTarget(null, $storedSchedule, $segment);
        }

        $keepStored = fn (): array => $this->resolveTarget($storedAttendance, $storedSchedule, $stored->target_segment ?: null);
        $sameTimes = $stored->corrected_check_in?->format('H:i') === $this->corrected_check_in?->format('H:i')
            && $stored->corrected_check_out?->format('H:i') === $this->corrected_check_out?->format('H:i');

        if ($sameTimes) {
            return $keepStored() === [] ? [] : $this->resolveTarget();
        }

        $inferred = $this->resolveTarget();

        if ($inferred === []) {
            return [];
        }

        return $keepStored() === [] ? [] : $this->resolveTarget();
    }

    /**
     * The target columns resolved by {@see self::resolveTarget()}, to save with the correction.
     *
     * @return array{attendance_id: int|null, shift_schedule_id: int|null, target_sequence: int|null, target_segment: int|null}
     */
    public function targetAttributes(): array
    {
        return [
            'attendance_id' => $this->attendance_id,
            'shift_schedule_id' => $this->shift_schedule_id,
            'target_sequence' => $this->target_sequence,
            'target_segment' => $this->target_segment,
        ];
    }

    /**
     * The moment the corrected check-in happened. It sits on the corrected date, except for a session
     * that crosses midnight, where a time before its check-in window opens (an hour before its start) and
     * not after its end belongs to the night after the date: 00:30 for a 22:00-06:00 shift on the 14th is
     * 00:30 on the 15th.
     */
    public function correctedCheckInMoment(): ?Carbon
    {
        if (! $this->corrected_check_in || ! $this->date) {
            return null;
        }

        $checkIn = Carbon::parse($this->date)->startOfDay()->setTimeFrom($this->corrected_check_in);
        $window = $this->targetWindow();

        if (! $window || $window['end']->isSameDay($window['start'])) {
            return $checkIn;
        }

        $opens = $window['start']->subMinutes(self::CHECK_IN_EARLIEST_MINUTES_BEFORE);
        $nextDay = $checkIn->copy()->addDay();

        return $checkIn->lessThan($opens) && $nextDay->lessThanOrEqualTo($window['end']) ? $nextDay : $checkIn;
    }

    /**
     * Whether the session this correction writes runs past midnight (22:00 - 06:00).
     */
    public function segmentCrossesMidnight(): bool
    {
        $window = $this->targetWindow();

        return $window !== null && ! $window['end']->isSameDay($window['start']);
    }

    /**
     * The moment the corrected check-out happened, after the check-in it closes (the given one, else the
     * corrected one, else the attendance's own). It is on the date, or on the next day when the client
     * says so (check_out_next_day, or a full datetime on the next day), when it is not after the check-in
     * (00:10 after a 14:00 check-in), or, without a check-in, when it is before the start of a session
     * that crosses midnight. The next day is only taken up to {@see SessionResolver::CHECK_OUT_GRACE_HOURS}
     * hours after the session's scheduled end ({@see self::nextDayLimit()}); later it stays on the date and
     * is refused as before the check-in. The same rule serves the saving hook, the submission checks and
     * the approval.
     */
    public function correctedCheckOutMoment(?CarbonInterface $checkIn = null): ?Carbon
    {
        if (! $this->corrected_check_out || ! $this->date) {
            return null;
        }

        $date = Carbon::parse($this->date)->startOfDay();
        $checkIn ??= $this->correctedCheckInMoment() ?? $this->targetAttendance()?->check_in;
        $checkOut = $date->copy()->setTimeFrom($this->corrected_check_out);
        $window = $this->targetWindow();

        $wantsNextDay = $this->check_out_next_day
            || $this->checkOutGivenDate === $date->copy()->addDay()->toDateString()
            || ($checkIn !== null && $checkOut->lessThan($checkIn))
            || ($checkIn === null && $window !== null && ! $window['end']->isSameDay($window['start']) && $checkOut->lessThan($window['start']));

        if (! $wantsNextDay) {
            return $checkOut;
        }

        $nextDay = $checkOut->copy()->addDay();
        $limit = $this->nextDayLimit($checkIn);

        return $limit !== null && $nextDay->lessThanOrEqualTo($limit) ? $nextDay : $checkOut;
    }

    /**
     * The latest moment a check-out on the next day is accepted: the session's scheduled end plus
     * {@see SessionResolver::CHECK_OUT_GRACE_HOURS} hours, or without any hours the check-in plus
     * {@see AttendanceCheckService::OVERNIGHT_OPEN_HOURS} hours; null when neither is known.
     */
    private function nextDayLimit(?CarbonInterface $checkIn): ?CarbonImmutable
    {
        $window = $this->targetWindow();

        if ($window !== null) {
            return $window['end']->addHours(SessionResolver::CHECK_OUT_GRACE_HOURS);
        }

        return $checkIn ? CarbonImmutable::instance($checkIn)->addHours(AttendanceCheckService::OVERNIGHT_OPEN_HOURS) : null;
    }

    /**
     * The scheduled window of the session this correction writes: the corrected shift's (a schedule
     * correction), else the one kept on the target row, else the planned session's; null on a day without
     * any hours (a day off, an unscheduled freelancer).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public function targetWindow(): ?array
    {
        if (! $this->date || ! $this->getWorkerProfile()) {
            return null;
        }

        $target = $this->target();
        $day = CarbonImmutable::parse(Carbon::parse($this->date)->toDateString());

        if ($this->corrected_shift_id && ($shift = Shift::query()->find($this->corrected_shift_id))) {
            $second = $target['segment'] === 2 && $shift->hasSecondSegment();

            return $this->windowOn($day, (string) ($second ? $shift->second_start_time : $shift->start_time), (string) ($second ? $shift->second_end_time : $shift->end_time));
        }

        $attendance = $target['attendance'];

        // The row's own schedule (kept on it, or the shift it was written with) comes first; a row without
        // either is read against the planned session, and only without one against the company hours.
        if ($attendance !== null && ($attendance->hasSnapshot() || $attendance->shift_id)) {
            return app(SessionResolver::class)->windowOf($attendance);
        }

        if ($target['session'] !== null) {
            return ['start' => $target['session']->startAt, 'end' => $target['session']->endAt];
        }

        return $attendance !== null ? app(SessionResolver::class)->windowOf($attendance) : null;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function windowOn(CarbonImmutable $day, string $startTime, string $endTime): array
    {
        $start = $day->setTimeFromTimeString($startTime);
        $end = $day->setTimeFromTimeString($endTime);

        return ['start' => $start, 'end' => substr($endTime, 0, 5) <= substr($startTime, 0, 5) ? $end->addDay() : $end];
    }

    /**
     * A date after today, or a corrected time after now, keyed by its field.
     *
     * @return array<string, string>
     */
    private function futureError(): array
    {
        if (Carbon::parse($this->date)->startOfDay()->greaterThan(today())) {
            return ['date' => self::FUTURE_DATE_MESSAGE];
        }

        if ($this->correctedCheckInMoment()?->greaterThan(now())) {
            return ['corrected_check_in' => 'Jam masuk yang dikoreksi belum terjadi. Koreksi hanya bisa diajukan untuk jam yang sudah lewat.'];
        }

        if ($this->correctedCheckOutMoment()?->greaterThan(now())) {
            return ['corrected_check_out' => 'Jam pulang yang dikoreksi belum terjadi. Koreksi hanya bisa diajukan untuk jam yang sudah lewat.'];
        }

        return [];
    }

    /**
     * A corrected shift or outlet the worker cannot name: the shift must be an active shift of the
     * worker's company, the outlet an active outlet of the worker's company or of a sister company of the
     * same client (as a cover check-in may name).
     *
     * @return array<string, string>
     */
    private function correctedValuesError(): array
    {
        $profile = $this->getWorkerProfile();

        if (! $profile) {
            return [];
        }

        $covers = app(OffScheduleCheckInService::class);

        try {
            if ($this->corrected_shift_id !== null) {
                $covers->coverShift($profile, (int) $this->corrected_shift_id);
            }
        } catch (AttendanceRuleException $exception) {
            return ['corrected_shift_id' => $exception->getMessage()];
        }

        try {
            $covers->coverLocation($profile, $this->corrected_company_location_id === null ? null : (int) $this->corrected_company_location_id);
        } catch (AttendanceRuleException $exception) {
            return ['corrected_company_location_id' => $exception->getMessage()];
        }

        return [];
    }

    /**
     * A corrected check-in outside the session's check-in window: from
     * {@see CHECK_IN_EARLIEST_MINUTES_BEFORE} minutes before its start up to its end (the next morning
     * for a session crossing midnight). Such a time is almost always a typo (13:00 for 23:00) and would
     * otherwise be recorded as hours of lateness. A day without any hours has nothing to judge against.
     *
     * @return array<string, string>
     */
    private function checkInWindowError(): array
    {
        $checkIn = $this->correctedCheckInMoment();
        $window = $checkIn ? $this->targetWindow() : null;

        if (! $window) {
            return [];
        }

        $opens = $window['start']->subMinutes(self::CHECK_IN_EARLIEST_MINUTES_BEFORE);

        if ($checkIn->greaterThanOrEqualTo($opens) && $checkIn->lessThanOrEqualTo($window['end'])) {
            return [];
        }

        return ['corrected_check_in' => sprintf(
            'Jam masuk %s berada jauh di luar jadwal shift (%s - %s). Isi jam masuk antara %s dan %s.',
            $checkIn->format('H:i'),
            $window['start']->format('H:i'),
            $window['end']->format('H:i'),
            $opens->format('H:i'),
            $window['end']->format('H:i'),
        )];
    }

    /**
     * A check-out not after the check-in it closes, keyed by the field to show it on. A correction of
     * only one time is compared with the attendance's own other time.
     *
     * @return array<string, string>
     */
    private function orderError(): array
    {
        $attendance = $this->targetAttendance();
        $checkIn = $this->correctedCheckInMoment() ?? $attendance?->check_in;
        $checkOut = $this->correctedCheckOutMoment() ?? $attendance?->check_out;

        if (! $checkIn || ! $checkOut || $checkOut->greaterThan($checkIn)) {
            return [];
        }

        $limit = $this->corrected_check_out ? $this->nextDayLimit($checkIn) : null;
        $nextDayHint = $limit
            ? sprintf(' Jam pulang lewat tengah malam hanya bisa dicatat sampai %d jam setelah jadwal selesai (%s).', SessionResolver::CHECK_OUT_GRACE_HOURS, $limit->format('d/m H:i'))
            : '';

        if ($this->corrected_check_in && $this->corrected_check_out) {
            return ['corrected_check_out' => 'Jam pulang harus setelah jam masuk.'.$nextDayHint];
        }

        if ($this->corrected_check_out) {
            return ['corrected_check_out' => sprintf('Jam pulang harus setelah jam masuk yang tercatat (%s).', $checkIn->format('H:i')).$nextDayHint];
        }

        return ['corrected_check_in' => sprintf('Jam masuk harus sebelum jam pulang yang tercatat (%s).', $checkOut->format('H:i'))];
    }

    /**
     * A check-out the client places on the next day (check_out_next_day, or a full datetime on the next
     * day) beyond the latest next-day moment ({@see self::nextDayLimit()}): refused instead of silently
     * read as the same evening.
     *
     * @return array<string, string>
     */
    private function nextDayError(): array
    {
        $date = Carbon::parse($this->date)->startOfDay();
        $saysNextDay = $this->check_out_next_day || $this->checkOutGivenDate === $date->copy()->addDay()->toDateString();
        $checkOut = $saysNextDay ? $this->correctedCheckOutMoment() : null;

        if (! $checkOut || ! $checkOut->isSameDay($date)) {
            return [];
        }

        $limit = $this->nextDayLimit($this->correctedCheckInMoment() ?? $this->targetAttendance()?->check_in);

        return ['corrected_check_out' => $limit
            ? sprintf('Jam pulang keesokan harinya hanya bisa dicatat sampai %d jam setelah jadwal selesai (%s).', SessionResolver::CHECK_OUT_GRACE_HOURS, $limit->format('d/m H:i'))
            : 'Jam pulang keesokan harinya tidak bisa dicatat tanpa jam masuk.'];
    }

    /**
     * Whether the corrected date is a day of approved leave: a leave row of the worker on that date
     * (no check-in) backed by a leave request that is still approved and covers the date, the same
     * rule check-in applies. A leave row whose leave was deleted or re-dated no longer blocks.
     */
    /**
     * The corrected session would overlap another session the worker worked: one stretch of time is
     * never worked twice.
     *
     * @return array<string, string>
     */
    private function overlapError(): array
    {
        if ($this->workerKey() === [] || (! $this->corrected_check_in && ! $this->corrected_check_out)) {
            return [];
        }

        $attendance = $this->targetAttendance();
        $checkIn = $this->correctedCheckInMoment() ?? $attendance?->check_in;

        if (! $checkIn) {
            return [];
        }

        $checkOut = $this->corrected_check_out ? $this->correctedCheckOutMoment($checkIn) : $attendance?->check_out;
        $overlap = $this->overlappingAttendance($checkIn, $checkOut, $attendance ? (int) $attendance->getKey() : null);

        return $overlap ? [($this->corrected_check_in ? 'corrected_check_in' : 'corrected_check_out') => self::overlapMessage($overlap)] : [];
    }

    /**
     * Another session of the worker (not rejected, with a check-in, dated the day before up to the day after)
     * whose span shares time with this one; an open session spans its check-in moment only.
     */
    private function overlappingAttendance(CarbonInterface $checkIn, ?CarbonInterface $checkOut, ?int $exceptId): ?Attendance
    {
        $date = CarbonImmutable::parse(Carbon::parse($this->date)->toDateString());
        $start = $checkIn->getTimestamp();
        $end = ($checkOut ?? $checkIn)->getTimestamp();

        return Attendance::query()
            ->where($this->workerKey())
            ->whereDate('date', '>=', $date->subDay()->toDateString())
            ->whereDate('date', '<=', $date->addDay()->toDateString())
            ->whereNotNull('check_in')
            ->where(fn ($notRejected) => $notRejected->whereNull('status')->orWhere('status', '!=', AttendanceStatus::Rejected->value))
            ->when($exceptId !== null, fn ($others) => $others->whereKeyNot($exceptId))
            ->orderBy('check_in')
            ->get()
            ->first(function (Attendance $other) use ($start, $end): bool {
                $otherStart = $other->check_in->getTimestamp();
                $otherEnd = ($other->check_out ?? $other->check_in)->getTimestamp();

                return $start === $end
                    ? $otherStart < $start && $otherEnd > $start
                    : $otherStart < $end && $otherEnd > $start;
            });
    }

    private static function overlapMessage(Attendance $other): string
    {
        return sprintf(
            'Jam koreksi bertabrakan dengan absensi lain pada %s (%s-%s).',
            $other->date->format('d/m/Y'),
            $other->check_in->format('H:i'),
            $other->check_out?->format('H:i') ?? 'belum pulang',
        );
    }

    private function isOnApprovedLeaveDay(): bool
    {
        $workerKey = $this->workerKey();

        if ($workerKey === [] || ! $this->date) {
            return false;
        }

        $date = Carbon::parse($this->date)->toDateString();

        $hasLeaveRow = Attendance::query()
            ->where($workerKey)
            ->whereDate('date', $date)
            ->where('status', AttendanceStatus::Leave->value)
            ->whereNull('check_in')
            ->exists();

        return $hasLeaveRow && LeaveRequest::query()
            ->where($workerKey)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    /**
     * The attendance row this correction would write, when it already exists.
     */
    private function targetAttendance(): ?Attendance
    {
        return $this->workerKey() === [] || ! $this->date ? null : $this->target()['attendance'];
    }

    /**
     * The session and attendance row this correction writes:
     *
     * 1. a named attendance (attendance_id) of the worker on the date;
     * 2. the target stored at submission (schedule number and part);
     * 3. a named schedule of the date (schedule_id), with its named part or, without one, the part the
     *    corrected time falls in;
     * 4. else inferred: a check-out-only correction closes the latest open row of the date (checked in,
     *    not out); on a day with one schedule the part follows the middle of a split shift's break (as
     *    before); on a day with several, the session whose window holds the corrected time (a check-in from
     *    an hour before its start up to its end, a check-out from its start up to six hours after its end).
     *    More than one or none of them: the worker must pick the schedule (error on "date").
     *
     * A day without any session and row is schedule 1, part 1.
     *
     * @return array{attendance: Attendance|null, session: WorkSession|null, schedule_id: int|null, sequence: int, segment: int, error: array<string, string>}
     */
    public function target(): array
    {
        $key = json_encode([
            $this->date ? Carbon::parse($this->date)->toDateString() : null,
            $this->corrected_check_in?->format('H:i:s'),
            $this->corrected_check_out?->format('H:i:s'),
            $this->attendance_id, $this->shift_schedule_id, $this->target_sequence, $this->target_segment,
            $this->workerKey(), $this->status,
        ]);

        if ($this->targetCache !== null && $this->targetCache['key'] === $key) {
            return $this->targetCache['target'];
        }

        $target = $this->resolveTargetNow();
        $this->targetCache = ['key' => $key, 'target' => $target];

        return $target;
    }

    /**
     * @return array{attendance: Attendance|null, session: WorkSession|null, schedule_id: int|null, sequence: int, segment: int, error: array<string, string>}
     */
    private function resolveTargetNow(): array
    {
        $profile = $this->getWorkerProfile();
        $result = fn (?Attendance $attendance, ?WorkSession $session, int $sequence, int $segment, ?int $scheduleId = null, array $error = []): array => [
            'attendance' => $attendance,
            'session' => $session,
            'schedule_id' => $scheduleId ?? ($attendance?->shift_schedule_id ?: null) ?? $session?->scheduleId,
            'sequence' => $sequence,
            'segment' => $segment,
            'error' => $error,
        ];

        if (! $profile || ! $this->date) {
            return $result(null, null, 1, 1);
        }

        $date = Carbon::parse($this->date)->toDateString();
        $sessions = collect(app(SessionResolver::class)->sessionsFor($profile, Carbon::parse($date)));
        $rows = Attendance::query()->with(['employee.company.policy', 'intern.company.policy', 'freelancer', 'shift'])->where($this->workerKey())->whereDate('date', $date)->get();
        $sessionOf = fn (int $sequence, int $segment): ?WorkSession => $sessions->first(fn (WorkSession $session): bool => $session->sequence === $sequence && $session->segment === $segment);
        $rowOf = fn (int $sequence, int $segment): ?Attendance => $rows->first(fn (Attendance $row): bool => (int) ($row->schedule_sequence ?: 1) === $sequence && (int) ($row->segment ?: 1) === $segment);
        $unknown = ['date' => self::UNKNOWN_TARGET_MESSAGE];

        // 1. A named attendance.
        if ($this->attendance_id) {
            $row = $rows->first(fn (Attendance $row): bool => (int) $row->getKey() === (int) $this->attendance_id);

            if (! $row) {
                return $result(null, null, 1, 1, null, ['attendance_id' => self::UNKNOWN_TARGET_MESSAGE]);
            }

            $sequence = (int) ($row->schedule_sequence ?: 1);
            $segment = (int) ($row->segment ?: 1);

            return $result($row, $sessionOf($sequence, $segment), $sequence, $segment);
        }

        // 2. The target stored at submission.
        if ($this->target_sequence && $this->target_segment) {
            $sequence = (int) $this->target_sequence;
            $segment = (int) $this->target_segment;
            $row = $rowOf($sequence, $segment);
            $session = $sessionOf($sequence, $segment);

            // The schedule it was filed against was removed (or replaced) meanwhile: never judged against another one.
            if ($this->shift_schedule_id && $row?->check_in === null && $session?->scheduleId !== (int) $this->shift_schedule_id) {
                return $result($row, null, $sequence, $segment, null, ['schedule_id' => self::SCHEDULE_GONE_MESSAGE]);
            }

            return $result($row, $session, $sequence, $segment, $this->shift_schedule_id ?: null);
        }

        // 3. A named schedule.
        if ($this->shift_schedule_id) {
            $parts = $sessions->filter(fn (WorkSession $session): bool => $session->scheduleId === (int) $this->shift_schedule_id)->values();

            if ($parts->isEmpty()) {
                return $result(null, null, 1, 1, null, ['schedule_id' => self::UNKNOWN_TARGET_MESSAGE]);
            }

            $sequence = $parts->first()->sequence;

            if ($this->target_segment) {
                $session = $parts->first(fn (WorkSession $session): bool => $session->segment === (int) $this->target_segment);

                return $session
                    ? $result($rowOf($sequence, $session->segment), $session, $sequence, $session->segment)
                    : $result(null, null, 1, 1, null, ['segment' => 'Bagian shift yang dipilih tidak ada pada jadwal ini.']);
            }

            $open = $this->corrected_check_in ? null : $rows->filter(fn (Attendance $row): bool => (int) ($row->schedule_sequence ?: 1) === $sequence && $this->isMissingCheckOut($row))->sortByDesc('check_in')->first();

            if ($open) {
                $segment = (int) ($open->segment ?: 1);

                return $result($open, $sessionOf($sequence, $segment), $sequence, $segment);
            }

            $holding = $parts->count() === 1 ? null : $this->sessionsHolding($parts, collect())->first();
            $segment = $holding['segment'] ?? ($parts->count() === 1 ? $parts->first()->segment : $this->breakMidpointSegment($parts->first()->shift));
            $session = $parts->first(fn (WorkSession $part): bool => $part->segment === $segment) ?? $parts->first();

            return $result($rowOf($sequence, $session->segment), $session, $sequence, $session->segment);
        }

        // 4. Inferred. A check-out alone closes the latest open row of the date (a rejected row, or one
        // still waiting for the geofence approver, is not one whose check-out was forgotten).
        if (! $this->corrected_check_in && $this->corrected_check_out) {
            $open = $rows->filter(fn (Attendance $row): bool => $this->isMissingCheckOut($row))->sortByDesc(fn (Attendance $row): int => $row->check_in->getTimestamp())->first();

            if ($open) {
                $sequence = (int) ($open->schedule_sequence ?: 1);
                $segment = (int) ($open->segment ?: 1);

                return $result($open, $sessionOf($sequence, $segment), $sequence, $segment);
            }
        }

        $checkedIn = $rows->filter(fn (Attendance $row): bool => $row->check_in !== null);
        $sequences = $sessions->map(fn (WorkSession $session): int => $session->sequence)
            ->merge($checkedIn->map(fn (Attendance $row): int => (int) ($row->schedule_sequence ?: 1)))
            ->unique()
            ->values();

        if ($sequences->count() <= 1) {
            $sequence = (int) ($sequences->first() ?? 1);
            $first = $sessions->first(fn (WorkSession $session): bool => $session->sequence === $sequence);
            $segment = $this->corrected_check_in || $this->corrected_check_out ? $this->breakMidpointSegment($first?->shift) : 1;

            return $result($rowOf($sequence, $segment), $sessionOf($sequence, $segment), $sequence, $segment);
        }

        $holding = $this->sessionsHolding($sessions, $checkedIn);

        if ($holding->count() !== 1) {
            return $result(null, null, 1, 1, null, ['date' => self::AMBIGUOUS_TARGET_MESSAGE]);
        }

        /** @var array{sequence: int, segment: int} $only */
        $only = $holding->first();

        return $result($rowOf($only['sequence'], $only['segment']), $sessionOf($only['sequence'], $only['segment']), $only['sequence'], $only['segment']);
    }

    /**
     * Whether the row is a check-in still waiting for its check-out: not a rejected row (it counts for
     * nothing) nor one still waiting for the geofence approver.
     */
    private function isMissingCheckOut(Attendance $row): bool
    {
        return $row->check_in !== null
            && $row->check_out === null
            && ! in_array($row->status, [AttendanceStatus::Rejected, AttendanceStatus::PendingApproval], true);
    }

    /**
     * The sessions (and checked-in rows without a planned session) whose window holds the corrected time:
     * a check-in from an hour before the start up to the end, a check-out alone from the start up to
     * {@see SessionResolver::CHECK_OUT_GRACE_HOURS} hours after the end; the time on the date or, for a
     * window past midnight, on the next day.
     *
     * @param  Collection<int, WorkSession>  $sessions
     * @param  Collection<int, Attendance>  $rows
     * @return Collection<string, array{sequence: int, segment: int}>
     */
    private function sessionsHolding(Collection $sessions, Collection $rows): Collection
    {
        $time = $this->corrected_check_in ?? $this->corrected_check_out;

        if (! $time) {
            return collect();
        }

        $isCheckIn = $this->corrected_check_in !== null;
        $moment = CarbonImmutable::parse(Carbon::parse($this->date)->toDateString())->setTimeFrom($time);
        $holds = function (CarbonImmutable $start, CarbonImmutable $end) use ($isCheckIn, $moment): bool {
            [$from, $until] = $isCheckIn
                ? [$start->subMinutes(self::CHECK_IN_EARLIEST_MINUTES_BEFORE), $end]
                : [$start, $end->addHours(SessionResolver::CHECK_OUT_GRACE_HOURS)];

            foreach ([$moment, $moment->addDay()] as $candidate) {
                if ($candidate->greaterThanOrEqualTo($from) && $candidate->lessThanOrEqualTo($until)) {
                    return true;
                }
            }

            return false;
        };

        $holding = collect();

        foreach ($sessions as $session) {
            if ($holds($session->startAt, $session->endAt)) {
                $holding->put($session->key(), ['sequence' => $session->sequence, 'segment' => $session->segment]);
            }
        }

        foreach ($rows as $row) {
            $key = ((int) ($row->schedule_sequence ?: 1)).':'.((int) ($row->segment ?: 1));
            $window = app(SessionResolver::class)->windowOf($row);

            if (! $holding->has($key) && $window && $holds($window['start'], $window['end'])) {
                $holding->put($key, ['sequence' => (int) ($row->schedule_sequence ?: 1), 'segment' => (int) ($row->segment ?: 1)]);
            }
        }

        return $holding;
    }

    /**
     * The part of a split shift the corrected time belongs to: the second one when it falls after the
     * middle of the break between the parts; 1 for any other shift.
     */
    private function breakMidpointSegment(?Shift $shift): int
    {
        $time = $this->corrected_check_in ?? $this->corrected_check_out;

        if (! $time || ! $shift?->hasSecondSegment()) {
            return 1;
        }

        $date = Carbon::parse($this->date);
        $breakStart = $date->copy()->setTimeFromTimeString((string) $shift->end_time);
        $breakEnd = $date->copy()->setTimeFromTimeString((string) $shift->second_start_time);
        $middle = $breakStart->copy()->addSeconds((int) ($breakStart->diffInSeconds($breakEnd) / 2));

        return $date->copy()->setTimeFrom($time)->greaterThan($middle) ? 2 : 1;
    }

    /**
     * Another pending or approved correction of the same worker for the same date and session that
     * changes the same thing (both the check-in, both the check-out, or both the schedule), which this one
     * would conflict with: once both are approved the later one silently overwrites the earlier. A
     * correction of the other time of that session does not conflict, a rejected or cancelled correction
     * may be submitted again, an approved schedule correction may be superseded by a new one (a wrong one
     * could not be fixed otherwise), and an admin's record of an edit is history, not a claim.
     */
    public function conflictingCorrection(): ?self
    {
        $workerKey = $this->workerKey();

        if (! $this->date || $workerKey === []) {
            return null;
        }

        $target = $this->target();
        $key = $target['sequence'].':'.$target['segment'];

        return static::query()
            ->where($workerKey)
            ->whereDate('date', Carbon::parse($this->date)->toDateString())
            ->whereIn('status', ['pending', 'approved'])
            ->claims()
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->with(['attendance', 'employee.company.policy', 'intern.company.policy', 'freelancer'])
            ->get()
            ->first(fn (self $other): bool => $other->resolvedTargetKey() === $key && $this->changesSameThingAs($other));
    }

    /**
     * Whether both corrections change the check-in, both the check-out, or both the schedule while the
     * other one still waits for approval.
     */
    private function changesSameThingAs(self $other): bool
    {
        return ($this->corrected_check_in && $other->corrected_check_in)
            || ($this->corrected_check_out && $other->corrected_check_out)
            || ($this->isScheduleCorrection() && $other->isScheduleCorrection() && $other->status === 'pending');
    }

    /**
     * "sequence:segment" of the row this correction writes: the row an approved correction wrote, else
     * its stored target, else the one inferred now.
     */
    private function resolvedTargetKey(): string
    {
        if ($this->status === 'approved' && $this->attendance) {
            return ((int) ($this->attendance->schedule_sequence ?: 1)).':'.((int) ($this->attendance->segment ?: 1));
        }

        $target = $this->target();

        return $target['sequence'].':'.$target['segment'];
    }

    /**
     * The worker column identifying this correction's worker, as used on the attendance.
     *
     * @return array<string, int>
     */
    private function workerKey(): array
    {
        foreach (['employee_id', 'intern_id', 'freelancer_id'] as $column) {
            if ($this->{$column}) {
                return [$column => $this->{$column}];
            }
        }

        return [];
    }

    /**
     * Record an admin's edit or deletion of an attendance on the panel: an approved correction of origin
     * "admin" holding the values before (before_values) and the times after, approved by the editor at
     * once (one approved step). Called in the same transaction as the change.
     *
     * @param  array<string, mixed>  $before  {@see Attendance::auditValues()} before the change
     */
    public static function recordAdminChange(Attendance $attendance, array $before, ?User $editor, string $reason, bool $deleted = false): self
    {
        $correction = new self;
        $correction->forceFill([
            'employee_id' => $attendance->employee_id,
            'intern_id' => $attendance->intern_id,
            'freelancer_id' => $attendance->freelancer_id,
            'attendance_id' => $deleted ? null : $attendance->getKey(),
            'date' => $attendance->date?->toDateString(),
            'corrected_check_in' => $deleted ? null : $attendance->check_in,
            'corrected_check_out' => $deleted ? null : $attendance->check_out,
            'check_out_next_day' => ! $deleted && $attendance->check_out !== null && $attendance->date !== null && ! $attendance->check_out->isSameDay($attendance->date),
            'reason' => $reason,
            'status' => 'approved',
            'current_step' => 1,
            'origin' => self::ORIGIN_ADMIN,
            'before_values' => $before,
            'shift_schedule_id' => $attendance->shift_schedule_id,
            'target_sequence' => (int) ($attendance->schedule_sequence ?: 1),
            'target_segment' => (int) ($attendance->segment ?: 1),
        ])->save();

        $correction->approvalSteps()->create([
            'step_order' => 1,
            'step_name' => $deleted ? 'Dihapus admin' : 'Diubah admin',
            'approver_type' => 'user',
            'user_id' => $editor?->getKey(),
            'status' => 'approved',
            'actioned_by' => $editor?->getKey(),
            'actioned_at' => now(),
            'notes' => $reason,
        ]);

        return $correction;
    }
}
