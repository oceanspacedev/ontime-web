<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who works where on a day: the schedules of the workers a manager oversees, grouped by outlet,
 * each with the attendance state of that day. A worker with several schedules that day has one entry per
 * schedule, each with only its own attendance; the counts stay per entry.
 */
final class OutletRosterService
{
    public const STATE_PRESENT = 'hadir';

    public const STATE_LATE = 'terlambat';

    public const STATE_DONE = 'pulang';

    public const STATE_WAITING = 'menunggu';

    public const STATE_MISSING = 'belum';

    public const STATE_UPCOMING = 'nanti';

    public const STATE_DAY_OFF = 'libur';

    /** Checked in, but the approver refused the attendance: not a worked (or paid) presence. */
    public const STATE_REJECTED = 'ditolak';

    public const STATE_LABELS = [
        self::STATE_PRESENT => 'Hadir',
        self::STATE_LATE => 'Terlambat',
        self::STATE_DONE => 'Pulang',
        self::STATE_WAITING => 'Menunggu persetujuan',
        self::STATE_MISSING => 'Belum absen',
        self::STATE_UPCOMING => 'Belum mulai',
        self::STATE_DAY_OFF => 'Libur',
        self::STATE_REJECTED => 'Ditolak',
    ];

    /**
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @return list<array{
     *     location: array{id: int, name: string}|null,
     *     counts: array<string, int>,
     *     entries: list<array{
     *         schedule_id: int,
     *         worker: array{type: string, id: int, company_id: int, number: string, name: string},
     *         shift: array{id: int, name: string, code: string|null, label: string, start_time: string, end_time: string}|null,
     *         section: string|null,
     *         borrowed_from: string|null,
     *         state: string,
     *         check_in: string|null,
     *         check_out: string|null,
     *         late_minutes: int,
     *         segments: list<array{start: string, end: string}>,
     *         attendances: list<array{segment: int, check_in: string|null, check_out: string|null, late_minutes: int}>,
     *         outlet_name: string|null,
     *         overtime_minutes: int,
     *         locked: bool,
     *         schedule_count: int,
     *         is_missing_check_out: bool,
     *     }>,
     * }>
     */
    public function roster(User $user, CarbonInterface $date, array $filters = []): array
    {
        $schedules = self::filterSchedules(ShiftSchedule::query()->visibleTo($user), $filters)
            ->whereDate('date', $date->toDateString())
            ->with([...ShiftSchedule::workerEagerLoads(), 'shift.division', 'location'])
            ->get()
            ->filter(fn (ShiftSchedule $schedule): bool => $schedule->getWorkerProfile() !== null);

        $rowCounts = self::rowCounts($schedules, $date);
        $attendances = $this->attendancesFor($schedules, $date, $rowCounts);
        AttendanceFigures::preload(collect($attendances)->flatten(1));

        return $schedules
            ->groupBy(fn (ShiftSchedule $schedule): string => (string) ($schedule->company_location_id ?? ''))
            ->map(function (Collection $group) use ($attendances, $rowCounts): array {
                /** @var CompanyLocation|null $location */
                $location = $group->first()->location;
                $entries = $group
                    ->map(fn (ShiftSchedule $schedule): array => $this->entry($schedule, $attendances, $rowCounts))
                    ->sortBy(fn (array $entry): string => ($entry['shift']['start_time'] ?? '99').'|'.$entry['worker']['name'])
                    ->values()
                    ->all();

                return [
                    'location' => $location ? ['id' => (int) $location->id, 'name' => $location->name] : null,
                    'counts' => $this->counts($entries),
                    'entries' => $entries,
                ];
            })
            ->sortBy(fn (array $outlet): string => $outlet['location'] === null ? "\u{FFFF}" : $outlet['location']['name'])
            ->values()
            ->all();
    }

    /**
     * Narrow schedules to one company (the worker's), one brand (the worker's or the outlet's) or one outlet.
     * Missing filters keep every schedule the user sees.
     *
     * @param  Builder<ShiftSchedule>  $query
     * @param  array{company_id?: int|null, division_id?: int|null, location_id?: int|null}  $filters
     * @return Builder<ShiftSchedule>
     */
    public static function filterSchedules(Builder $query, array $filters): Builder
    {
        $companyId = $filters['company_id'] ?? null;
        $divisionId = $filters['division_id'] ?? null;
        $locationId = $filters['location_id'] ?? null;

        return $query
            ->when($locationId, fn (Builder $atOutlet) => $atOutlet->where('company_location_id', $locationId))
            ->when($companyId, fn (Builder $inCompany) => $inCompany->where(fn (Builder $workers) => self::whereWorkerIn($workers, 'company_id', (int) $companyId)))
            ->when($divisionId, fn (Builder $inBrand) => $inBrand->where(fn (Builder $brand) => self::whereWorkerIn($brand, 'division_id', (int) $divisionId)
                ->orWhereIn('company_location_id', CompanyLocation::query()->select('id')->where('division_id', $divisionId))));
    }

    /**
     * @param  Builder<ShiftSchedule>  $query
     * @return Builder<ShiftSchedule>
     */
    private static function whereWorkerIn(Builder $query, string $column, int $value): Builder
    {
        return $query
            ->whereIn('employee_id', Employee::query()->select('id')->where($column, $value))
            ->orWhereIn('intern_id', Intern::query()->select('id')->where($column, $value))
            ->orWhereIn('freelancer_id', Freelancer::query()->select('id')->where($column, $value));
    }

    /**
     * The day's attendance rows of each listed schedule, keyed by schedule id: the rows linked to it, and for
     * rows without a link (written before the link existed) the worker's rows when the worker has exactly
     * one schedule row that date. A row linked to nothing and of a worker with several schedules that day
     * belongs to no entry (the live view lists it under "Tanpa jadwal").
     *
     * @param  Collection<int, ShiftSchedule>  $schedules
     * @param  array<string, int>  $rowCounts
     * @return array<int, Collection<int, Attendance>>
     */
    private function attendancesFor(Collection $schedules, CarbonInterface $date, array $rowCounts): array
    {
        $attendances = [];

        foreach (['employee', 'intern', 'freelancer'] as $type) {
            $ids = $schedules->pluck("{$type}_id")->filter()->unique()->values();

            if ($ids->isEmpty()) {
                continue;
            }

            $byWorker = Attendance::query()
                ->whereIn("{$type}_id", $ids)
                ->whereDate('date', $date->toDateString())
                ->orderBy('schedule_sequence')
                ->orderBy('segment')
                ->get()
                ->groupBy("{$type}_id");

            foreach ($schedules->filter(fn (ShiftSchedule $schedule): bool => $schedule->{"{$type}_id"} !== null) as $schedule) {
                $workerId = (int) $schedule->{"{$type}_id"};
                $rows = $byWorker->get($workerId) ?? collect();
                $soleRow = ($rowCounts["{$type}:{$workerId}"] ?? 0) === 1;

                $attendances[(int) $schedule->id] = $rows
                    ->filter(fn (Attendance $row): bool => $row->shift_schedule_id !== null
                        ? (int) $row->shift_schedule_id === (int) $schedule->id
                        : $soleRow)
                    ->values();
            }
        }

        return $attendances;
    }

    /**
     * How many schedule rows each worker of the list has on the date (all rows, also the ones the user does
     * not see), keyed "type:id".
     *
     * @param  Collection<int, ShiftSchedule>  $schedules
     * @return array<string, int>
     */
    public static function rowCounts(Collection $schedules, CarbonInterface $date): array
    {
        $counts = [];

        foreach (['employee', 'intern', 'freelancer'] as $type) {
            $ids = $schedules->pluck("{$type}_id")->filter()->unique()->values();

            if ($ids->isEmpty()) {
                continue;
            }

            ShiftSchedule::query()
                ->whereIn("{$type}_id", $ids)
                ->whereDate('date', $date->toDateString())
                ->selectRaw("{$type}_id as worker_id, count(*) as total")
                ->groupBy("{$type}_id")
                ->toBase()
                ->get()
                ->each(function (object $row) use ($type, &$counts): void {
                    $counts["{$type}:{$row->worker_id}"] = (int) $row->total;
                });
        }

        return $counts;
    }

    /**
     * @param  array<int, Collection<int, Attendance>>  $attendances
     * @param  array<string, int>  $rowCounts
     * @return array<string, mixed>
     */
    private function entry(ShiftSchedule $schedule, array $attendances, array $rowCounts): array
    {
        $worker = $schedule->getWorkerProfile();
        [$type, $number] = match (true) {
            $worker instanceof Employee => ['employee', $worker->nip],
            $worker instanceof Intern => ['intern', $worker->nis],
            $worker instanceof Freelancer => ['freelancer', $worker->freelancer_number],
        };
        $rows = $attendances[(int) $schedule->id] ?? collect();
        $shift = $schedule->shift;
        // The segment in progress; a finished first segment of a split shift leaves the second still to come.
        $attendance = $rows->last();
        $secondPending = $shift?->hasSecondSegment() && $rows->count() === 1 && (int) ($attendance->segment ?: 1) === 1 && $attendance->check_out !== null;
        $worked = $rows->reject(fn (Attendance $row): bool => $row->status === AttendanceStatus::Rejected);
        $figures = $worked->map(fn (Attendance $row): array => AttendanceFigures::forAttendance($row));

        return [
            'schedule_id' => (int) $schedule->id,
            'worker' => ['type' => $type, 'id' => (int) $worker->getKey(), 'company_id' => (int) $worker->company_id, 'number' => (string) $number, 'name' => (string) $worker->full_name],
            'shift' => $shift ? [
                'id' => (int) $shift->id,
                'name' => $shift->name,
                'code' => $shift->code,
                'label' => $shift->label(),
                'start_time' => substr((string) $shift->start_time, 0, 5),
                'end_time' => substr((string) $shift->end_time, 0, 5),
                'talenta_code' => $shift->talenta_code,
                'segments' => $shift->segments(),
            ] : null,
            'location_id' => $schedule->company_location_id,
            'borrowed_from' => $schedule->borrowedFromBrand(),
            'section' => $schedule->section,
            'state' => $this->state($schedule, $attendance, $secondPending),
            'check_in' => $rows->first()?->check_in?->format('H:i'),
            'check_out' => $attendance?->check_out?->format('H:i'),
            'late_minutes' => (int) $worked->sum(fn (Attendance $row): int => $row->reportedLateMinutes()),
            'segments' => $shift?->segments() ?? [],
            'attendances' => $rows->map(fn (Attendance $row): array => [
                'segment' => (int) $row->segment,
                'check_in' => $row->check_in?->format('H:i'),
                'check_out' => $row->check_out?->format('H:i'),
                'late_minutes' => (int) $row->late_minutes,
            ])->all(),
            'outlet_name' => $schedule->location?->name,
            'overtime_minutes' => (int) $figures->sum('overtime_minutes'),
            'locked' => $rows->contains(fn (Attendance $row): bool => (int) $row->shift_schedule_id === (int) $schedule->id && $row->check_in !== null && $row->status !== AttendanceStatus::Rejected),
            'schedule_count' => $rowCounts["{$type}:{$worker->getKey()}"] ?? 1,
            'is_missing_check_out' => $figures->contains(fn (array $row): bool => $row['is_missing_check_out']),
        ];
    }

    private function state(ShiftSchedule $schedule, ?Attendance $attendance, bool $secondPending = false): string
    {
        return match (true) {
            $schedule->is_day_off => self::STATE_DAY_OFF,
            $secondPending => $this->hasStarted($schedule, 2) ? self::STATE_MISSING : self::STATE_UPCOMING,
            $attendance?->check_in === null => $this->hasStarted($schedule) ? self::STATE_MISSING : self::STATE_UPCOMING,
            $attendance->status === AttendanceStatus::Rejected => self::STATE_REJECTED,
            $attendance->status === AttendanceStatus::PendingApproval => self::STATE_WAITING,
            $attendance->check_out !== null => self::STATE_DONE,
            $attendance->status === AttendanceStatus::Late => self::STATE_LATE,
            default => self::STATE_PRESENT,
        };
    }

    /**
     * Whether the shift has begun, so a missing check-in is late rather than still to come.
     */
    private function hasStarted(ShiftSchedule $schedule, int $segment = 1): bool
    {
        $time = $segment === 2 ? $schedule->shift?->second_start_time : $schedule->shift?->start_time;
        $start = $schedule->date->copy()->setTimeFromTimeString((string) ($time ?? '00:00'));

        return now()->greaterThanOrEqualTo($start);
    }

    /**
     * @param  list<array{state: string}>  $entries
     * @return array<string, int>
     */
    private function counts(array $entries): array
    {
        $counts = array_fill_keys(array_keys(self::STATE_LABELS), 0);

        foreach ($entries as $entry) {
            $counts[$entry['state']]++;
        }

        return ['scheduled' => count($entries) - $counts[self::STATE_DAY_OFF]] + $counts;
    }
}
