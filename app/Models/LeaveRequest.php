<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Traits\ScopesToWorkerVisibility;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class LeaveRequest extends Model
{
    use HasFactory, ScopesToWorkerVisibility;

    /** The only leave type that uses the yearly quota. */
    public const TYPE_ANNUAL = 'annual_leave';

    /**
     * Leave types shared by the panel and the API, with their labels.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::TYPE_ANNUAL => 'Cuti Tahunan',
        'permission' => 'Izin',
        'sick' => 'Sakit',
        'maternity' => 'Cuti Melahirkan',
        'unpaid' => 'Cuti Tanpa Gaji',
    ];

    /**
     * Codes older versions of the mobile app send.
     *
     * @var array<string, string>
     */
    public const TYPE_ALIASES = [
        'annual' => self::TYPE_ANNUAL,
        'permitted' => 'permission',
    ];

    /** How many days back a leave may start, so a forgotten sick day can still be filed. */
    public const MAX_DAYS_BEFORE_TODAY = 31;

    /** How far ahead a leave may start. */
    public const MAX_DAYS_AFTER_TODAY = 365;

    /** The longest leave in one request (six months of maternity leave fits). */
    public const MAX_DAYS_PER_REQUEST = 180;

    /**
     * Why the period cannot be filed, keyed by the field to show it on, or an empty array: a start
     * more than {@see MAX_DAYS_BEFORE_TODAY} days back or {@see MAX_DAYS_AFTER_TODAY} days ahead, or
     * more than {@see MAX_DAYS_PER_REQUEST} days in one request. Approving a leave writes one
     * attendance row per day, so an unbounded period (year 1 to 9999) is refused here.
     *
     * @return array<string, string>
     */
    public static function periodErrors(CarbonInterface $startDate, CarbonInterface $endDate): array
    {
        $start = Carbon::parse($startDate->toDateString());
        $end = Carbon::parse($endDate->toDateString());

        if ($start->lessThan(today()->subDays(self::MAX_DAYS_BEFORE_TODAY))) {
            return ['start_date' => 'Tanggal mulai paling lama '.self::MAX_DAYS_BEFORE_TODAY.' hari sebelum hari ini.'];
        }

        if ($start->greaterThan(today()->addDays(self::MAX_DAYS_AFTER_TODAY))) {
            return ['start_date' => 'Tanggal mulai paling jauh '.self::MAX_DAYS_AFTER_TODAY.' hari setelah hari ini.'];
        }

        if ($end->greaterThanOrEqualTo($start) && (int) $start->diffInDays($end) + 1 > self::MAX_DAYS_PER_REQUEST) {
            return ['end_date' => 'Satu pengajuan paling lama '.self::MAX_DAYS_PER_REQUEST.' hari. Bagi menjadi beberapa pengajuan.'];
        }

        return [];
    }

    public static function normalizeType(mixed $type): mixed
    {
        return is_string($type) ? (self::TYPE_ALIASES[$type] ?? $type) : $type;
    }

    public static function typeLabel(?string $type): string
    {
        return self::TYPES[self::normalizeType($type)] ?? (string) $type;
    }

    protected $fillable = [
        'employee_id',
        'intern_id',
        'freelancer_id',
        'leave_type',
        'start_date',
        'end_date',
        'days_count',
        'reason',
        'attachment',
        'status',
        'current_step',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_count' => 'integer',
            'current_step' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

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

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalRequestStep::class, 'approvable');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns the linked worker profile regardless of type.
     */
    public function getWorkerProfile(): Employee|Intern|Freelancer|null
    {
        return $this->employee ?? $this->intern ?? $this->freelancer;
    }

    /**
     * Returns whether this leave request was submitted by a freelancer
     * (freelancers do not consume quota from company policy).
     */
    public function isFromFreelancer(): bool
    {
        return $this->freelancer_id !== null;
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    /**
     * The worker's attendance rows on the leave days. A split-shift day has one row per segment.
     *
     * @return Builder<Attendance>
     */
    private function attendanceOnLeaveDays(): Builder
    {
        return Attendance::query()
            ->where(array_filter([
                'employee_id' => $this->employee_id,
                'intern_id' => $this->intern_id,
                'freelancer_id' => $this->freelancer_id,
            ]))
            ->whereDate('date', '>=', $this->start_date->toDateString())
            ->whereDate('date', '<=', $this->end_date->toDateString());
    }

    /**
     * Leave days on which the worker already checked in (any segment, including a check-in still
     * waiting for geofence approval). Approving the leave would bury that attendance, so the final
     * approval is refused for these days. `$lockRows` reads them with a locking read, so the
     * approval sees a check-in committed after its transaction started.
     *
     * @return list<string> dates as Y-m-d, in order
     */
    public function checkedInDates(bool $lockRows = false): array
    {
        return $this->attendanceOnLeaveDays()
            ->whereNotNull('check_in')
            ->when($lockRows, fn (Builder $query) => $query->lockForUpdate())
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Marks every leave day as leave. Every existing row of a day (each segment of a split-shift day)
     * is updated, and a day without any row gets one.
     *
     * The rows are read with a locking read, and a row holding a check-in (one committed after the
     * approval's own check, see ApprovalFlowService::assertLeaveCanBeApproved()) refuses the whole
     * leave instead of burying that attendance; so does a row created by a parallel check-in while
     * the missing days are written. Called inside the approval transaction, which then rolls back.
     *
     * @throws ValidationException when a leave day holds a check-in
     */
    public function applyLeave(): void
    {
        $notes = 'Cuti / Izin Disetujui: '.$this->reason;
        $existingDates = [];

        $attendances = $this->attendanceOnLeaveDays()->lockForUpdate()->get();
        $checkedIn = $attendances->filter(fn (Attendance $attendance): bool => $attendance->check_in !== null);

        if ($checkedIn->isNotEmpty()) {
            $this->refuseCheckedInDays($checkedIn->map(fn (Attendance $attendance): string => Carbon::parse($attendance->getRawOriginal('date'))->toDateString())->all());
        }

        $attendances->each(function (Attendance $attendance) use ($notes, &$existingDates): void {
            $attendance->update([
                'status' => AttendanceStatus::Leave->value,
                'notes' => $notes,
            ]);

            $existingDates[Carbon::parse($attendance->getRawOriginal('date'))->toDateString()] = true;
        });

        $workerCondition = array_filter([
            'employee_id' => $this->employee_id,
            'intern_id' => $this->intern_id,
            'freelancer_id' => $this->freelancer_id,
        ]);

        foreach (CarbonPeriod::create($this->start_date, $this->end_date) as $date) {
            if (isset($existingDates[$date->toDateString()])) {
                continue;
            }

            try {
                Attendance::create(array_merge($workerCondition, [
                    'date' => $date->toDateString(),
                    'status' => AttendanceStatus::Leave->value,
                    'notes' => $notes,
                ]));
            } catch (UniqueConstraintViolationException) {
                $this->refuseCheckedInDays([$date->toDateString()]);
            }
        }
    }

    /**
     * Refuses the approval because the worker checked in on these leave days.
     *
     * @param  list<string>  $dates  dates as Y-m-d
     *
     * @throws ValidationException
     */
    private function refuseCheckedInDays(array $dates): never
    {
        $name = $this->getWorkerProfile()?->full_name ?? 'Pekerja ini';
        $labels = collect($dates)->unique()->sort()->map(fn (string $date): string => Carbon::parse($date)->format('d/m/Y'))->implode(', ');

        throw ValidationException::withMessages([
            'approve' => "{$name} sudah melakukan presensi pada tanggal {$labels}. Tolak pengajuan ini, atau minta pekerja mengubah tanggal cuti/izinnya.",
        ]);
    }
}
