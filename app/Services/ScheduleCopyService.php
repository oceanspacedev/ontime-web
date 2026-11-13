<?php

namespace App\Services;

use App\Models\ShiftSchedule;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Copies a week of schedules onto another week (Monday to Sunday), so a manager starts from last
 * week's roster instead of an empty one. Shared by the panel and the mobile API.
 *
 * Only workers inside the user's data access are copied. Days that already have a schedule are
 * left alone, and shifts that were switched off since are skipped, so a copy never overwrites work.
 */
final class ScheduleCopyService
{
    /**
     * @return array{copied: int, skipped_existing: int, skipped_unavailable: int, from: string, to: string}
     */
    public function copyWeek(User $user, CarbonInterface $sourceWeek, CarbonInterface $targetWeek, ?int $companyId = null): array
    {
        $source = Carbon::parse($sourceWeek)->startOfWeek();
        $target = Carbon::parse($targetWeek)->startOfWeek();
        $result = ['copied' => 0, 'skipped_existing' => 0, 'skipped_unavailable' => 0, 'from' => $source->toDateString(), 'to' => $target->toDateString()];

        if ($source->equalTo($target) || ! $user->canManageWorkers()) {
            return $result;
        }

        $offsetDays = (int) $source->diffInDays($target, false);

        $schedules = ShiftSchedule::query()
            ->visibleTo($user)
            ->with([...ShiftSchedule::workerEagerLoads(), 'shift'])
            ->whereBetween('date', [$source->toDateString(), $source->copy()->endOfWeek()->toDateString()])
            ->get();

        foreach ($schedules as $schedule) {
            $worker = $schedule->getWorkerProfile();

            if (! $worker || ($companyId !== null && (int) $worker->company_id !== $companyId) || ! $user->canManageWorker($worker)) {
                $result['skipped_unavailable']++;

                continue;
            }

            if (! $schedule->is_day_off && ! $schedule->shift?->is_active) {
                $result['skipped_unavailable']++;

                continue;
            }

            $column = app(WorkScheduleService::class)->workerColumn($worker);
            $date = $schedule->date->copy()->addDays($offsetDays)->toDateString();

            if (ShiftSchedule::query()->where($column, $worker->getKey())->whereDate('date', $date)->exists()) {
                $result['skipped_existing']++;

                continue;
            }

            ShiftSchedule::create([
                $column => $worker->getKey(),
                'date' => $date,
                'shift_id' => $schedule->shift_id,
                'company_location_id' => $schedule->company_location_id,
                'is_day_off' => $schedule->is_day_off,
                'section' => $schedule->section,
                'notes' => $schedule->notes,
                'created_by' => $user->id,
            ]);
            $result['copied']++;
        }

        return $result;
    }

    /**
     * "12 jadwal disalin. 3 hari dilewati karena sudah terisi."
     *
     * @param  array{copied: int, skipped_existing: int, skipped_unavailable: int}  $result
     */
    public function summary(array $result): string
    {
        $parts = ["{$result['copied']} jadwal disalin."];

        if ($result['skipped_existing'] > 0) {
            $parts[] = "{$result['skipped_existing']} hari dilewati karena sudah terisi.";
        }

        if ($result['skipped_unavailable'] > 0) {
            $parts[] = "{$result['skipped_unavailable']} dilewati karena shift nonaktif atau di luar akses Anda.";
        }

        return implode(' ', $parts);
    }
}
