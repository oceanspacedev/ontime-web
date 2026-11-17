<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\Resignation;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\ExpectedWorkDays;
use App\Support\StorageUrl;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportAndCalendarApiController extends Controller
{
    public function kalenderCuti(Request $request): JsonResponse
    {
        // Same rule as the Kalender Cuti Tim page: interns do not see the team leave calendar.
        if ($request->user()->intern !== null) {
            return response()->json(['message' => 'Kalender cuti tim tidak tersedia untuk peserta magang.'], 403);
        }

        $validated = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $month = (int) ($validated['month'] ?? now()->month);
        $year = (int) ($validated['year'] ?? now()->year);
        $companyId = $validated['company_id'] ?? null;

        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $endOfMonth = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $query = LeaveRequest::with(['employee.company', 'employee.division', 'intern.company', 'intern.division', 'freelancer.company', 'freelancer.division'])
            ->whereIn('status', ['approved', 'pending'])
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('start_date', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('end_date', [$startOfMonth, $endOfMonth])
                    ->orWhere(function ($q2) use ($startOfMonth, $endOfMonth) {
                        $q2->where('start_date', '<=', $startOfMonth)
                            ->where('end_date', '>=', $endOfMonth);
                    });
            });

        if ($companyId) {
            $query->where(function ($q) use ($companyId) {
                $q->whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
                    ->orWhereHas('intern', fn ($i) => $i->where('company_id', $companyId))
                    ->orWhereHas('freelancer', fn ($f) => $f->where('company_id', $companyId));
            });
        }

        $query->visibleWithColleaguesTo($request->user());

        $leaveRequests = $query->orderBy('start_date', 'asc')->get()->map(function ($leave) {
            $person = $leave->employee ?? $leave->intern ?? $leave->freelancer;

            return [
                'id' => $leave->id,
                'person_name' => $person?->full_name ?? 'Pengguna',
                'user_name' => $person?->full_name ?? 'Pengguna',
                'company_name' => $person?->company?->name,
                'division_name' => $person?->division?->name,
                'department' => $person?->division?->name ?? $person?->company?->name ?? 'Tim',
                'leave_type' => $leave->leave_type,
                'start_date' => $leave->start_date?->format('Y-m-d'),
                'end_date' => $leave->end_date?->format('Y-m-d'),
                'days_count' => $leave->days_count,
                'status' => $leave->status,
                'reason' => $leave->reason,
                'master_face_photo' => StorageUrl::for($person?->master_face_photo),
            ];
        });

        return response()->json([
            'month' => $month,
            'year' => $year,
            'data' => $leaveRequests,
        ]);
    }

    public function laporanAbsensi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->toDateString();
        $companyId = $validated['company_id'] ?? null;

        if (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) > 366) {
            return response()->json(['message' => 'Rentang tanggal maksimal 366 hari.', 'errors' => ['end_date' => ['Rentang tanggal maksimal 366 hari.']]], 422);
        }

        $user = $request->user();
        // A half-open range: the last day still matches where dates are stored with a time (SQLite).
        $scoped = Attendance::query()
            ->where('date', '>=', $startDate)
            ->where('date', '<', Carbon::parse($endDate)->addDay()->toDateString())
            ->visibleTo($user)
            ->when($companyId, fn (Builder $query) => $query->where(fn (Builder $inCompany) => $inCompany
                ->whereIn('employee_id', Employee::query()->select('id')->where('company_id', $companyId))
                ->orWhereIn('intern_id', Intern::query()->select('id')->where('company_id', $companyId))
                ->orWhereIn('freelancer_id', Freelancer::query()->select('id')->where('company_id', $companyId))));
        $query = (clone $scoped)->with([...Attendance::workerEagerLoads(), 'employee.user', 'intern.user', 'freelancer.user']);

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        // Approved leave per worker-day: leave that overlaps the range (also one that wraps it), clipped to it.
        $leaveDays = $this->approvedLeaveDays($user, $companyId, $start, $end);

        // Each worker-day is counted once: present (late is a part of present), leave or absent.
        $summary = $this->workerDaySummary($user, $companyId, $scoped, $leaveDays, $start, $end);

        $attendances = $query->latest('date')->paginate(30);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_present' => $summary['present'],
                'total_late' => $summary['late'],
                'total_leave' => $summary['leave'],
                'total_absent' => $summary['absent'],
            ],
            'data' => AttendanceResource::collection($attendances->items()),
            'pagination' => [
                'current_page' => $attendances->currentPage(),
                'last_page' => $attendances->lastPage(),
                'per_page' => $attendances->perPage(),
                'total' => $attendances->total(),
            ],
        ]);
    }

    /** Worker types with the columns that bound their employment; an employee ends on an approved resignation. */
    private const WORKER_TYPES = [
        'employee' => ['model' => Employee::class, 'from' => 'join_date', 'until' => null],
        'intern' => ['model' => Intern::class, 'from' => 'start_date', 'until' => 'end_date'],
        'freelancer' => ['model' => Freelancer::class, 'from' => 'start_date', 'until' => 'end_date'],
    ];

    /** Worker statuses that still expect attendance. */
    private const WORKING_STATUSES = ['active', 'extended'];

    /**
     * Approved leave days per worker inside [start, end], keyed "type:id|Y-m-d".
     *
     * @return array<string, true>
     */
    private function approvedLeaveDays(User $user, int|string|null $companyId, Carbon $start, Carbon $end): array
    {
        $leaves = LeaveRequest::query()
            ->where('status', 'approved')
            ->where('start_date', '<', $end->copy()->addDay()->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->visibleTo($user)
            ->when($companyId, fn (Builder $query) => $query->whereWorkerInCompanies([(int) $companyId]))
            ->toBase()
            ->get(['employee_id', 'intern_id', 'freelancer_id', 'start_date', 'end_date']);

        $days = [];

        foreach ($leaves as $leave) {
            if (! ($worker = $this->workerKey($leave)) || ! $leave->start_date || ! $leave->end_date) {
                continue;
            }

            $cursor = Carbon::parse($leave->start_date)->startOfDay()->max($start)->copy();
            $until = Carbon::parse($leave->end_date)->startOfDay()->min($end)->copy();

            for (; $cursor->lte($until); $cursor->addDay()) {
                $days["{$worker}|{$cursor->toDateString()}"] = true;
            }
        }

        return $days;
    }

    /**
     * Present, late, leave and absent worker-days in the range; each worker-day is exactly one of present,
     * leave or absent, and late is the part of present that started late.
     *
     * - Present: any segment of the day was worked: checked in, or on time, late or pending approval
     *   (pending counts as present, as before). A rejected segment is not worked: its check-in time stays,
     *   but it adds neither presence nor lateness.
     * - Leave: an approved leave day without a worked segment, also over an explicit absent row.
     * - Absent: an explicit absent or rejected row without a worked segment or leave on a day the worker was
     *   expected to work (so a rejected check-in on a rostered day off stays a day off, as in the team recap),
     *   plus every expected day (ExpectedWorkDays) up to yesterday without any attendance row or leave.
     *
     * Attendance is streamed per worker-day in one grouped query; workers, schedules and holidays are each
     * read once.
     *
     * @param  Builder<Attendance>  $scoped
     * @param  array<string, true>  $leaveDays
     * @return array{present: int, late: int, leave: int, absent: int}
     */
    private function workerDaySummary(User $user, int|string|null $companyId, Builder $scoped, array $leaveDays, Carbon $start, Carbon $end): array
    {
        $present = 0;
        $late = 0;
        $absent = 0;
        /** @var array<string, true> $recorded */
        $recorded = [];
        /** @var array<string, true> $worked */
        $worked = [];
        /** @var array<string, true> $explicitAbsent absent or rejected worker-days without a worked segment or leave */
        $explicitAbsent = [];

        $perWorkerDay = (clone $scoped)->toBase()
            ->select(['employee_id', 'intern_id', 'freelancer_id', 'date'])
            ->selectRaw("MAX(CASE WHEN COALESCE(status, '') <> 'rejected' AND (status IN ('on_time', 'late', 'pending_approval') OR check_in IS NOT NULL) THEN 1 ELSE 0 END) AS is_present")
            ->selectRaw("MAX(CASE WHEN COALESCE(status, '') <> 'rejected' AND (status = 'late' OR late_minutes > 0 OR (status = 'pending_approval' AND check_in_late_minutes > 0)) THEN 1 ELSE 0 END) AS is_late")
            ->selectRaw("MAX(CASE WHEN status IN ('absent', 'rejected') THEN 1 ELSE 0 END) AS is_absent")
            ->groupBy('employee_id', 'intern_id', 'freelancer_id', 'date');

        foreach ($perWorkerDay->cursor() as $day) {
            $worker = $this->workerKey($day);
            $key = $worker ? "{$worker}|".Carbon::parse($day->date)->toDateString() : null;

            if ($key) {
                $recorded[$key] = true;
            }

            if ((int) $day->is_present === 1) {
                $present++;
                $late += (int) $day->is_late === 1 ? 1 : 0;

                if ($key) {
                    $worked[$key] = true;
                }
            } elseif ((int) $day->is_absent === 1 && ! ($key && isset($leaveDays[$key]))) {
                if ($key) {
                    $explicitAbsent[$key] = true;
                } else {
                    $absent++;
                }
            }
        }

        $leave = count(array_diff_key($leaveDays, $worked));

        return [
            'present' => $present,
            'late' => $late,
            'leave' => $leave,
            'absent' => $absent + $this->rosterAbsences($user, $companyId, $recorded, $explicitAbsent, $leaveDays, $start, $end),
        ];
    }

    /**
     * No-shows judged against the roster (ExpectedWorkDays: the shift schedule, holidays, the policy week and
     * the worker's employment), counted per worker-day:
     * - an explicit absent or rejected row counts only on a day the worker was expected to work,
     * - a day up to yesterday without any attendance row or approved leave counts when the worker was expected.
     *
     * Like the team recap, a worker is on a roster from the Monday of the week of their first shift schedule
     * onward: from then on only rostered working days are expected. Days before that keep the policy week, so
     * the same worker-day gives the same answer whatever range is requested.
     *
     * @param  array<string, true>  $recorded
     * @param  array<string, true>  $explicitAbsent
     * @param  array<string, true>  $leaveDays
     */
    private function rosterAbsences(User $user, int|string|null $companyId, array $recorded, array $explicitAbsent, array $leaveDays, Carbon $start, Carbon $end): int
    {
        $until = $end->copy()->min(ExpectedWorkDays::lastCountableDay());
        [$schedules, $rosteredSince] = $this->roster($user, $start, $end);
        $calendar = ExpectedWorkDays::between($start, $end);

        /** @var array<string, array{company_id: int|null, from: string|null, until: string|null}> $employment */
        $employment = [];
        foreach (self::WORKER_TYPES as $type => ['model' => $model, 'from' => $fromColumn, 'until' => $untilColumn]) {
            foreach ($this->workersEmployedSince($model, $user, $companyId, $fromColumn, $untilColumn, $start) as $worker) {
                $employment["{$type}:{$worker->id}"] = [
                    'company_id' => $worker->company_id !== null ? (int) $worker->company_id : null,
                    'from' => $worker->employed_from ? Carbon::parse($worker->employed_from)->toDateString() : null,
                    'until' => $worker->employed_until ? Carbon::parse($worker->employed_until)->toDateString() : null,
                ];
            }
        }

        $isExpected = fn (string $worker, string $date): bool => $calendar->isExpected(
            $date,
            $schedules["{$worker}|{$date}"] ?? null,
            $employment[$worker]['company_id'] ?? null,
            $employment[$worker]['from'] ?? null,
            $employment[$worker]['until'] ?? null,
            isset($rosteredSince[$worker]) && $date >= $rosteredSince[$worker],
        );

        $absent = 0;

        foreach (array_keys($explicitAbsent) as $key) {
            [$worker, $date] = explode('|', $key);

            if ($isExpected($worker, $date)) {
                $absent++;
            }
        }

        if ($start->gt($until)) {
            return $absent;
        }

        $dates = [];
        for ($cursor = $start->copy(); $cursor->lte($until); $cursor->addDay()) {
            $dates[] = $cursor->toDateString();
        }

        foreach (array_keys($employment) as $worker) {
            foreach ($dates as $date) {
                $key = "{$worker}|{$date}";

                if (isset($recorded[$key]) || isset($leaveDays[$key])) {
                    continue;
                }

                if ($isExpected($worker, $date)) {
                    $absent++;
                }
            }
        }

        return $absent;
    }

    /**
     * The roster of the workers in the user's scope: their schedule per worker-day in [start, end] (true for
     * a day off, false for a shift; a shift wins over a day off), and per worker the Monday of the week of
     * their first shift schedule ever, from which on they are on a roster.
     *
     * @return array{0: array<string, bool>, 1: array<string, string>}
     */
    private function roster(User $user, Carbon $start, Carbon $end): array
    {
        $before = $end->copy()->addDay()->toDateString();
        $schedules = [];

        foreach (ShiftSchedule::query()
            ->visibleTo($user)
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', $before)
            ->toBase()
            ->select(['employee_id', 'intern_id', 'freelancer_id', 'date', 'is_day_off'])
            ->cursor() as $row) {
            if ($worker = $this->workerKey($row)) {
                $key = "{$worker}|".Carbon::parse($row->date)->toDateString();
                $schedules[$key] = ($schedules[$key] ?? true) && (bool) $row->is_day_off;
            }
        }

        $rosteredSince = [];

        foreach (ShiftSchedule::query()
            ->visibleTo($user)
            ->where('date', '<', $before)
            ->toBase()
            ->select(['employee_id', 'intern_id', 'freelancer_id'])
            ->selectRaw('MIN(date) AS first_date')
            ->groupBy('employee_id', 'intern_id', 'freelancer_id')
            ->cursor() as $row) {
            if ($worker = $this->workerKey($row)) {
                $rosteredSince[$worker] = Carbon::parse($row->first_date)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
            }
        }

        return [$schedules, $rosteredSince];
    }

    /**
     * Workers in scope whose employment overlaps [start, ...]: those still working, plus those whose
     * employment has ended on a known date on or after start, so past no-shows stay in historical reports.
     * An employee's end is the last working day of an approved resignation; a worker that ended without a
     * known date is left out, since the days they were still expected cannot be told.
     *
     * @param  class-string<Employee|Intern|Freelancer>  $model
     * @return Collection<int, object{id: int, company_id: int|null, employed_from: mixed, employed_until: mixed}>
     */
    private function workersEmployedSince(string $model, User $user, int|string|null $companyId, string $fromColumn, ?string $untilColumn, Carbon $start): Collection
    {
        $query = DataAccessService::scopeWorkers($model::query(), $user)
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId));

        $query->select(['id', 'status', 'company_id', "{$fromColumn} as employed_from"]);

        if ($untilColumn) {
            $query->addSelect("{$untilColumn} as employed_until");
        } else {
            $query->addSelect(['employed_until' => Resignation::query()
                ->select('last_working_day')
                ->whereColumn('employee_id', $query->qualifyColumn('id'))
                ->where('status', 'approved')
                ->whereNotNull('last_working_day')
                ->orderByDesc('last_working_day')
                ->limit(1)]);
        }

        return DB::query()
            ->fromSub($query, 'workers')
            ->where(fn (QueryBuilder $workers) => $workers
                ->whereIn('status', self::WORKING_STATUSES)
                ->orWhere('employed_until', '>=', $start->toDateString()))
            ->get(['id', 'status', 'company_id', 'employed_from', 'employed_until'])
            ->map(function (object $worker) use ($untilColumn): object {
                if (! $untilColumn && in_array($worker->status, self::WORKING_STATUSES, true)
                    && $worker->employed_until && Carbon::parse($worker->employed_until)->isBefore(today())) {
                    $worker->employed_until = null;
                }

                return $worker;
            });
    }

    private function workerKey(object $row): ?string
    {
        foreach (array_keys(self::WORKER_TYPES) as $type) {
            if ($row->{"{$type}_id"}) {
                return "{$type}:{$row->{"{$type}_id"}}";
            }
        }

        return null;
    }
}
