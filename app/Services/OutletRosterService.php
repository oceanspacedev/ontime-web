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

    public const STATE_DAY_OFF = 'libur';

    public const STATE_LABELS = [
        self::STATE_PRESENT => 'Hadir',
        self::STATE_LATE => 'Terlambat',
        self::STATE_DONE => 'Pulang',
        self::STATE_WAITING => 'Menunggu persetujuan',
        self::STATE_MISSING => 'Belum absen',
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
     *         state: string,
     *         check_in: string|null,
     *         check_out: string|null,
     *         late_minutes: int,
     *     }>,
     * }>
     */
    public function roster(User $user, CarbonInterface $date): array
    {
        $schedules = ShiftSchedule::query()
            ->visibleTo($user)
            ->whereDate('date', $date->toDateString())
            ->with(['employee', 'intern', 'freelancer', 'shift.division', 'location'])
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
     * @return array<string, Attendance> Keyed "employee:ID".
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
                ->get()
                ->each(function (Attendance $attendance) use ($type, &$attendances): void {
                    $attendances["{$type}:{$attendance->{"{$type}_id"}}"] = $attendance;
                });
        }

        return $attendances;
    }

    /**
     * @param  array<string, Attendance>  $attendances
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
        $attendance = $attendances["{$type}:{$worker->getKey()}"] ?? null;
        $shift = $schedule->shift;

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
            ] : null,
            'location_id' => $schedule->company_location_id,
            'section' => $schedule->section,
            'state' => $this->state($schedule, $attendance),
            'check_in' => $attendance?->check_in?->format('H:i'),
            'check_out' => $attendance?->check_out?->format('H:i'),
            'late_minutes' => (int) ($attendance?->late_minutes ?? 0),
        ];
    }

    private function state(ShiftSchedule $schedule, ?Attendance $attendance): string
    {
        return match (true) {
            $schedule->is_day_off => self::STATE_DAY_OFF,
            $attendance?->check_in === null => self::STATE_MISSING,
            $attendance->check_out !== null => self::STATE_DONE,
            $attendance->status === AttendanceStatus::PendingApproval => self::STATE_WAITING,
            $attendance->status === AttendanceStatus::Late => self::STATE_LATE,
            default => self::STATE_PRESENT,
        };
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
