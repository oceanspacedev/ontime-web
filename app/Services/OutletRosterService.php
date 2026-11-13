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
use Illuminate\Support\Collection;

/**
 * Who works where on a day: the schedules of the workers a manager oversees, grouped by outlet,
 * each with the attendance state of that day.
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

    public const STATE_LABELS = [
        self::STATE_PRESENT => 'Hadir',
        self::STATE_LATE => 'Terlambat',
        self::STATE_DONE => 'Pulang',
        self::STATE_WAITING => 'Menunggu persetujuan',
        self::STATE_MISSING => 'Belum absen',
        self::STATE_UPCOMING => 'Belum mulai',
        self::STATE_DAY_OFF => 'Libur',
    ];

    /**
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
     *     }>,
     * }>
     */
    public function roster(User $user, CarbonInterface $date): array
    {
        $schedules = ShiftSchedule::query()
            ->visibleTo($user)
            ->whereDate('date', $date->toDateString())
            ->with([...ShiftSchedule::workerEagerLoads(), 'shift.division', 'location'])
            ->get()
            ->filter(fn (ShiftSchedule $schedule): bool => $schedule->getWorkerProfile() !== null);

        $attendances = $this->attendancesFor($schedules, $date);

        return $schedules
            ->groupBy(fn (ShiftSchedule $schedule): string => (string) ($schedule->company_location_id ?? ''))
            ->map(function (Collection $group) use ($attendances): array {
                /** @var CompanyLocation|null $location */
                $location = $group->first()->location;
                $entries = $group
                    ->map(fn (ShiftSchedule $schedule): array => $this->entry($schedule, $attendances))
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
     * @param  Collection<int, ShiftSchedule>  $schedules
     * @return array<string, Collection<int, Attendance>> The day's rows (one per segment), keyed "employee:ID".
     */
    private function attendancesFor(Collection $schedules, CarbonInterface $date): array
    {
        $attendances = [];

        foreach (['employee', 'intern', 'freelancer'] as $type) {
            $ids = $schedules->pluck("{$type}_id")->filter()->unique()->values();

            if ($ids->isEmpty()) {
                continue;
            }

            Attendance::query()
                ->whereIn("{$type}_id", $ids)
                ->whereDate('date', $date->toDateString())
                ->orderBy('segment')
                ->get()
                ->groupBy("{$type}_id")
                ->each(function (Collection $rows, int|string $workerId) use ($type, &$attendances): void {
                    $attendances["{$type}:{$workerId}"] = $rows->values();
                });
        }

        return $attendances;
    }

    /**
     * @param  array<string, Collection<int, Attendance>>  $attendances
     * @return array<string, mixed>
     */
    private function entry(ShiftSchedule $schedule, array $attendances): array
    {
        $worker = $schedule->getWorkerProfile();
        [$type, $number] = match (true) {
            $worker instanceof Employee => ['employee', $worker->nip],
            $worker instanceof Intern => ['intern', $worker->nis],
            $worker instanceof Freelancer => ['freelancer', $worker->freelancer_number],
        };
        $rows = $attendances["{$type}:{$worker->getKey()}"] ?? collect();
        $shift = $schedule->shift;
        // The segment in progress; a finished first segment of a split shift leaves the second still to come.
        $attendance = $rows->last();
        $secondPending = $shift?->hasSecondSegment() && $attendance?->check_out !== null && $rows->count() < 2;

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
            'late_minutes' => (int) $rows->sum('late_minutes'),
            'segments' => $shift?->segments() ?? [],
            'attendances' => $rows->map(fn (Attendance $row): array => [
                'segment' => (int) $row->segment,
                'check_in' => $row->check_in?->format('H:i'),
                'check_out' => $row->check_out?->format('H:i'),
                'late_minutes' => (int) $row->late_minutes,
            ])->all(),
        ];
    }

    private function state(ShiftSchedule $schedule, ?Attendance $attendance, bool $secondPending = false): string
    {
        return match (true) {
            $schedule->is_day_off => self::STATE_DAY_OFF,
            $secondPending => $this->hasStarted($schedule, 2) ? self::STATE_MISSING : self::STATE_UPCOMING,
            $attendance?->check_in === null => $this->hasStarted($schedule) ? self::STATE_MISSING : self::STATE_UPCOMING,
            $attendance->check_out !== null => self::STATE_DONE,
            $attendance->status === AttendanceStatus::PendingApproval => self::STATE_WAITING,
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
