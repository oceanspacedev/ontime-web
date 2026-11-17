<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Services\WorkScheduleService;
use App\Traits\ScopesToWorkerVisibility;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceCorrection extends Model
{
    use HasFactory, ScopesToWorkerVisibility;

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
    ];

    /**
     * Formats accepted for a corrected time: the "HH:MM" the mobile app and the panel send, or a
     * full datetime from older API clients. The time is anchored on the corrected date anyway.
     */
    public const TIME_INPUT_FORMATS = 'H:i,H:i:s,Y-m-d H:i,Y-m-d H:i:s';

    /**
     * Shown when a worker submits a correction for a date and segment that already has a pending
     * or approved correction: a second one would silently overwrite the first once approved.
     */
    public const DUPLICATE_MESSAGE = 'Sudah ada pengajuan koreksi yang menunggu persetujuan atau sudah disetujui untuk jam yang sama pada tanggal ini. Batalkan atau ubah pengajuan tersebut, jangan mengajukan ulang.';

    /**
     * How long before the segment start a corrected check-in may lie, matching the window in which
     * the app accepts a check-in.
     */
    public const CHECK_IN_EARLIEST_MINUTES_BEFORE = 60;

    protected static function booted(): void
    {
        // The form and API send only a time (e.g. "08:30"), which the datetime cast places on
        // the submission day. Anchor both times on the attendance date being corrected.
        static::saving(function (AttendanceCorrection $correction): void {
            if (! $correction->date) {
                return;
            }

            foreach (['corrected_check_in', 'corrected_check_out'] as $column) {
                $time = $correction->getAttribute($column);

                if ($time) {
                    $correction->setAttribute($column, Carbon::parse($correction->date)->setTimeFrom($time));
                }
            }

            // A check-out earlier than the check-in belongs to the next day, but only on a shift that
            // crosses midnight (22:00 - 06:00); on a day shift swapped times are refused at submission.
            if ($correction->corrected_check_in && $correction->corrected_check_out
                && $correction->corrected_check_out->lessThan($correction->corrected_check_in)
                && $correction->segmentCrossesMidnight()) {
                $correction->corrected_check_out = $correction->corrected_check_out->copy()->addDay();
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
        ];
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
     * Shown when a correction targets an attendance the approver refused.
     */
    public const REJECTED_ATTENDANCE_MESSAGE = 'Absensi pada tanggal ini sudah ditolak oleh atasan, sehingga tidak bisa dikoreksi. Hubungi atasan Anda bila penolakan perlu ditinjau ulang.';

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
     * Write the corrected times onto the attendance and mark the correction approved.
     *
     * A correction without any corrected time (a row saved before the times were validated, or
     * one that skipped the form request) would only flag an attendance as corrected, or create an
     * empty one, so it is refused and nothing is touched. So is a correction for a date that has not
     * happened yet (its times were checked against the clock when submitted), one whose attendance was already written by another approved
     * correction, which it would otherwise silently overwrite, and one whose attendance row is created
     * by a parallel request (a check-in or another approval) while this one is applied.
     *
     * An attendance that became pending geofence approval or rejected after the correction was
     * submitted keeps that status: the corrected times are recorded, but only the geofence approver
     * settles a pending row (its lateness at check-in is updated for that decision) and a rejected
     * row stays rejected with its reason.
     *
     * @throws ValidationException when neither a corrected check-in nor check-out is set, when the
     *                             date lies in the future, when another approved
     *                             correction already wrote the same time of the attendance, when the
     *                             resulting check-out would not be after the check-in, or when the
     *                             attendance row was created concurrently
     */
    public function applyCorrection(): void
    {
        if (! $this->corrected_check_in && ! $this->corrected_check_out) {
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

        $search = ['date' => $this->date, ...$this->workerKey()];

        $workSchedule = app(WorkScheduleService::class);
        $profile = $this->getWorkerProfile();
        $segment = $this->targetSegment();

        // Locked so a geofence decision on the same row cannot interleave with this write.
        $attendance = Attendance::query()
            ->where($this->workerKey())
            ->whereDate('date', Carbon::parse($this->date)->toDateString())
            ->where('segment', $segment)
            ->lockForUpdate()
            ->first() ?? new Attendance([...$search, 'segment' => $segment]);

        // A day of approved leave stays a leave day, as check-in refuses it and leave approval refuses
        // a worked day: otherwise the day would count both as paid leave and as worked.
        if ($this->isOnApprovedLeaveDay()) {
            throw ValidationException::withMessages([
                'approve' => self::ON_LEAVE_MESSAGE.' Tolak pengajuan ini.',
            ]);
        }

        // Another approved correction only blocks this one when both wrote the same time: an approved
        // check-in correction leaves the day's check-out free for a later check-out correction.
        if ($attendance->exists && static::query()
            ->where('attendance_id', $attendance->id)
            ->where('status', 'approved')
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

        $checkIn = $this->correctedCheckInMoment();

        if ($checkIn) {
            $attendance->check_in = $checkIn;
        }
        if ($this->corrected_check_out) {
            $attendance->check_out = $this->checkOutAfter($attendance->check_in);
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

        $attendance->is_corrected = true;

        $pendingLateMinutes = null;

        if ($checkIn && $profile) {
            // Judge the corrected time against the hours of its own segment, so a split shift's
            // second segment is not measured from the first one, and against the business date, so
            // an after-midnight check-in of a night shift is measured from the evening start.
            $hours = $workSchedule->resolve($profile, Carbon::parse($this->date), $segment);
            $lateMinutes = $workSchedule->lateMinutes($hours, $checkIn, Carbon::parse($this->date));
            $attendance->shift_id ??= $hours['shift']?->id;

            if ($attendance->status === AttendanceStatus::PendingApproval) {
                $attendance->check_in_late_minutes = $pendingLateMinutes = $lateMinutes;
            } elseif ($attendance->status === AttendanceStatus::Rejected) {
                $attendance->late_minutes = $lateMinutes;
            } else {
                $attendance->late_minutes = $lateMinutes;
                $attendance->status = $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::OnTime;
            }
        } elseif ($checkIn && ! $attendance->status) {
            $attendance->status = AttendanceStatus::OnTime;
        }

        try {
            DB::transaction(function () use ($attendance, $pendingLateMinutes): void {
                $attendance->save();

                // The saving hook clears the check-in lateness of a moved check-in when the value
                // itself did not change; the geofence approver must still judge the corrected time.
                if ($pendingLateMinutes !== null && $attendance->check_in_late_minutes !== $pendingLateMinutes) {
                    $attendance->check_in_late_minutes = $pendingLateMinutes;
                    $attendance->saveQuietly();
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'approve' => 'Data absensi pekerja untuk tanggal ini baru saja dibuat oleh proses lain. Muat ulang halaman lalu setujui ulang pengajuan ini.',
            ]);
        }

        $this->update([
            'status' => 'approved',
            'attendance_id' => $attendance->id,
        ]);
    }

    /**
     * Why this correction cannot be submitted, keyed by the field to show it on, or an empty array:
     * a date or time that has not happened yet, a check-in far outside the segment's hours, a check-out
     * not after the check-in (the corrected one or the attendance's own), an attendance still waiting
     * for or refused by the geofence approver, a day of approved leave, or another pending or approved correction of the same
     * date, segment and time.
     *
     * @return array<string, string>
     */
    public function submissionErrors(): array
    {
        if (! $this->date) {
            return [];
        }

        if ($error = $this->futureError()) {
            return $error;
        }

        if ($error = $this->checkInWindowError()) {
            return $error;
        }

        if ($error = $this->orderError()) {
            return $error;
        }

        if ($this->workerKey() !== []) {
            $status = $this->targetAttendance()?->status;

            if ($status === AttendanceStatus::PendingApproval) {
                return ['date' => self::PENDING_ATTENDANCE_MESSAGE];
            }

            if ($status === AttendanceStatus::Rejected) {
                return ['date' => self::REJECTED_ATTENDANCE_MESSAGE];
            }

            if ($this->isOnApprovedLeaveDay()) {
                return ['date' => self::ON_LEAVE_MESSAGE];
            }
        }

        if ($this->conflictingCorrection()) {
            return ['date' => self::DUPLICATE_MESSAGE];
        }

        return [];
    }

    /**
     * The moment the corrected check-in happened. It sits on the corrected date, except for a shift
     * that crosses midnight, where a time in the morning gap (before the middle of the off hours
     * between the shift end and start) belongs to the night after the date: 00:30 for a 22:00-06:00
     * shift on the 14th is 00:30 on the 15th.
     */
    public function correctedCheckInMoment(): ?Carbon
    {
        if (! $this->corrected_check_in || ! $this->date) {
            return null;
        }

        $checkIn = Carbon::parse($this->date)->startOfDay()->setTimeFrom($this->corrected_check_in);
        $hours = $this->segmentHours();

        return $hours ? $this->placedInNightShift($checkIn, $hours) : $checkIn;
    }

    /**
     * Whether the hours of the segment this correction writes run past midnight (22:00 - 06:00), so
     * a check-out before the check-in belongs to the next day. Without a worker the company default
     * of a day shift is assumed.
     */
    public function segmentCrossesMidnight(): bool
    {
        $hours = $this->segmentHours();

        return $hours !== null && ! $hours['is_day_off'] && $this->hoursCrossMidnight($hours);
    }

    /**
     * The moment the corrected check-out happened, after the check-in it closes: the corrected one,
     * otherwise the attendance's own.
     */
    public function correctedCheckOutMoment(): ?Carbon
    {
        if (! $this->corrected_check_out || ! $this->date) {
            return null;
        }

        $checkIn = $this->correctedCheckInMoment() ?? $this->targetAttendance()?->check_in;

        return $this->checkOutAfter($checkIn);
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
     * A corrected check-in outside the segment's check-in window: from
     * {@see CHECK_IN_EARLIEST_MINUTES_BEFORE} minutes before its start up to its end (the next morning
     * for a shift crossing midnight). Such a time is almost always a typo (13:00 for 23:00) and would
     * otherwise be recorded as hours of lateness. A day off or an unscheduled freelancer has no hours
     * to judge against.
     *
     * @return array<string, string>
     */
    private function checkInWindowError(): array
    {
        $checkIn = $this->correctedCheckInMoment();
        $hours = $checkIn ? $this->segmentHours() : null;
        $profile = $this->getWorkerProfile();

        if (! $hours || ! $profile || app(WorkScheduleService::class)->isOffSchedule($profile, $hours)) {
            return [];
        }

        $date = Carbon::parse($this->date)->startOfDay();
        $start = $date->copy()->setTimeFromTimeString($hours['start_time']);
        $end = $date->copy()->setTimeFromTimeString($hours['end_time']);

        if ($this->hoursCrossMidnight($hours)) {
            $end->addDay();
        }

        $opens = $start->copy()->subMinutes(self::CHECK_IN_EARLIEST_MINUTES_BEFORE);

        if ($checkIn->greaterThanOrEqualTo($opens) && $checkIn->lessThanOrEqualTo($end)) {
            return [];
        }

        return ['corrected_check_in' => sprintf(
            'Jam masuk %s berada jauh di luar jadwal shift (%s - %s). Isi jam masuk antara %s dan %s.',
            $checkIn->format('H:i'),
            $start->format('H:i'),
            $end->format('H:i'),
            $opens->format('H:i'),
            $end->format('H:i'),
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

        $nightHint = $this->segmentCrossesMidnight() ? '' : ' Jam pulang lewat tengah malam hanya berlaku untuk shift malam.';

        if ($this->corrected_check_in && $this->corrected_check_out) {
            return ['corrected_check_out' => 'Jam pulang harus setelah jam masuk.'.$nightHint];
        }

        if ($this->corrected_check_out) {
            return ['corrected_check_out' => sprintf('Jam pulang harus setelah jam masuk yang tercatat (%s).', $checkIn->format('H:i')).$nightHint];
        }

        return ['corrected_check_in' => sprintf('Jam masuk harus sebelum jam pulang yang tercatat (%s).', $checkOut->format('H:i'))];
    }

    /**
     * The working hours of the segment this correction writes: those of the shift already recorded on
     * the attendance (a cover shift of an off-schedule check-in included), otherwise the worker's
     * schedule for the date. Null without a worker.
     *
     * @return array{shift: Shift|null, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string}|null
     */
    private function segmentHours(): ?array
    {
        $profile = $this->getWorkerProfile();

        if (! $profile || ! $this->date) {
            return null;
        }

        $workSchedule = app(WorkScheduleService::class);
        $segment = $this->targetSegment();
        $recordedShift = $this->targetAttendance()?->shift;

        return $recordedShift
            ? $workSchedule->hoursForShift($recordedShift, $profile, $segment)
            : $workSchedule->resolve($profile, Carbon::parse($this->date), $segment);
    }

    /**
     * @param  array{start_time: string, end_time: string}  $hours
     */
    private function hoursCrossMidnight(array $hours): bool
    {
        return substr($hours['end_time'], 0, 5) <= substr($hours['start_time'], 0, 5);
    }

    /**
     * Whether the corrected date is a day of approved leave: a leave row of the worker on that date
     * (no check-in) backed by a leave request that is still approved and covers the date, the same
     * rule check-in applies. A leave row whose leave was deleted or re-dated no longer blocks.
     */
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
        $workerKey = $this->workerKey();

        if ($workerKey === [] || ! $this->date) {
            return null;
        }

        return Attendance::query()
            ->where($workerKey)
            ->whereDate('date', Carbon::parse($this->date)->toDateString())
            ->where('segment', $this->targetSegment())
            ->first();
    }

    /**
     * Places a time of a shift crossing midnight on the right side of it: from
     * {@see CHECK_IN_EARLIEST_MINUTES_BEFORE} minutes before the start it is that evening (an early
     * arrival included), and up to the shift end it is the night's after-midnight part, the next day.
     * Any other time stays on the date and is refused by {@see checkInWindowError()}.
     *
     * @param  array{is_day_off: bool, start_time: string, end_time: string}  $hours
     */
    private function placedInNightShift(Carbon $moment, array $hours): Carbon
    {
        if ($hours['is_day_off'] || ! $this->hoursCrossMidnight($hours)) {
            return $moment;
        }

        $day = $moment->copy()->startOfDay();
        $opens = $day->copy()->setTimeFromTimeString($hours['start_time'])->subMinutes(self::CHECK_IN_EARLIEST_MINUTES_BEFORE);
        $end = $day->copy()->setTimeFromTimeString($hours['end_time']);

        if ($moment->greaterThanOrEqualTo($opens)) {
            return $moment;
        }

        return $moment->lessThanOrEqualTo($end) ? $moment->copy()->addDay() : $moment;
    }

    /**
     * Another pending or approved correction of the same worker for the same date and segment that
     * changes the same time (both the check-in, or both the check-out), which this one would conflict
     * with: once both are approved the later one silently overwrites the earlier. A correction of the
     * other time of that day does not conflict, and a rejected or cancelled correction may be
     * submitted again.
     */
    public function conflictingCorrection(): ?self
    {
        $workerKey = $this->workerKey();

        if (! $this->date || $workerKey === []) {
            return null;
        }

        $segment = $this->targetSegment();

        return static::query()
            ->where($workerKey)
            ->whereDate('date', Carbon::parse($this->date)->toDateString())
            ->whereIn('status', ['pending', 'approved'])
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->with('attendance')
            ->get()
            ->first(fn (self $other): bool => $other->resolvedSegment() === $segment && $this->changesSameTimeAs($other));
    }

    /**
     * Whether both corrections change the check-in, or both change the check-out.
     */
    private function changesSameTimeAs(self $other): bool
    {
        return ($this->corrected_check_in && $other->corrected_check_in)
            || ($this->corrected_check_out && $other->corrected_check_out);
    }

    /**
     * The attendance segment this correction writes: the one an approved correction already wrote,
     * otherwise the one {@see targetSegment()} picks.
     */
    private function resolvedSegment(): int
    {
        if ($this->status === 'approved' && $this->attendance) {
            return (int) $this->attendance->segment;
        }

        return $this->targetSegment();
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
     * The attendance segment the correction is written to. A check-out-only correction closes the
     * worker's open segment (checked in, not yet out) of that date, so a late check-out of a split
     * shift's first segment past the break midpoint does not create a check-out-only second segment.
     * Otherwise the segment follows {@see segmentOfCorrection()}.
     */
    private function targetSegment(): int
    {
        $profile = $this->getWorkerProfile();

        if (! $profile) {
            return 1;
        }

        if (! $this->corrected_check_in && $this->corrected_check_out) {
            $openSegment = Attendance::query()
                ->where($this->workerKey())
                ->whereDate('date', Carbon::parse($this->date)->toDateString())
                ->whereNotNull('check_in')
                ->whereNull('check_out')
                ->orderByDesc('segment')
                ->value('segment');

            if ($openSegment !== null) {
                return (int) $openSegment;
            }
        }

        return $this->segmentOfCorrection(app(WorkScheduleService::class), $profile);
    }

    /**
     * The corrected check-out on a segment crossing midnight, moved to the next day when it falls
     * before the check-in it closes (the corrected one, or the row's own when only the check-out is
     * corrected) or, without any check-in, before the shift start. On any other segment it stays on
     * the date, so a check-out before the check-in is caught as an error instead of read as an
     * overnight stay.
     */
    private function checkOutAfter(?CarbonInterface $checkIn): Carbon
    {
        $checkOut = Carbon::parse($this->date)->setTimeFrom($this->corrected_check_out);
        $hours = $this->segmentHours();

        if (! $hours || $hours['is_day_off'] || ! $this->hoursCrossMidnight($hours)) {
            return $checkOut;
        }

        if ($checkIn) {
            return $checkOut->lessThan($checkIn) ? $checkOut->addDay() : $checkOut;
        }

        return $checkOut->format('H:i') < substr($hours['start_time'], 0, 5) ? $checkOut->addDay() : $checkOut;
    }

    /**
     * The segment a correction belongs to: the second one when the day's shift is split and the
     * corrected time falls after the middle of the break between the segments.
     */
    private function segmentOfCorrection(WorkScheduleService $workSchedule, mixed $profile): int
    {
        $time = $this->corrected_check_in ?? $this->corrected_check_out;
        $shift = $time ? $workSchedule->resolve($profile, Carbon::parse($this->date))['shift'] : null;

        if (! $shift?->hasSecondSegment()) {
            return 1;
        }

        $date = Carbon::parse($this->date);
        $breakStart = $date->copy()->setTimeFromTimeString((string) $shift->end_time);
        $breakEnd = $date->copy()->setTimeFromTimeString((string) $shift->second_start_time);
        $middle = $breakStart->copy()->addSeconds((int) ($breakStart->diffInSeconds($breakEnd) / 2));

        return $date->copy()->setTimeFrom($time)->greaterThan($middle) ? 2 : 1;
    }
}
