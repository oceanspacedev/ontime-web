<?php

namespace App\Services;

use App\Models\Holiday;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The one rule for whether a worker is expected to work on a date, shared by laporan absensi and the team
 * recap so a no-show is counted the same way in both:
 * 1. never before the worker's start or after their employment ended,
 * 2. a dated shift schedule decides: a shift makes the day a working day (weekends and holidays included),
 *    a day off does not,
 * 3. a worker on a roster (from the Monday of the week of their first shift schedule onward) works only the
 *    rostered days, so a day without a schedule is not a working day for them (as in the team recap),
 * 4. otherwise a holiday from the master (national, or of the worker's company) is not a working day,
 * 5. otherwise (a worker without any roster, on a default shift or the company hours) the company policy week
 *    applies: Monday to Friday (there is no custom work week yet).
 *
 * Today never counts as a no-show: the worker's shift may still be ahead, so the last countable day is yesterday.
 */
final class ExpectedWorkDays
{
    /**
     * @param  array<string, true>  $globalHolidays  dates of national holidays and holidays without a company
     * @param  array<int, array<string, true>>  $companyHolidays  dates per company id
     */
    private function __construct(
        private readonly array $globalHolidays,
        private readonly array $companyHolidays,
    ) {}

    /**
     * Load the holidays inside [start, end] once, for checking many worker-days.
     */
    public static function between(CarbonInterface $start, CarbonInterface $end): self
    {
        $global = [];
        $perCompany = [];

        $holidays = Holiday::query()
            ->where('date', '>=', $start->toDateString())
            ->where('date', '<', Carbon::parse($end)->addDay()->toDateString())
            ->toBase()
            ->get(['company_id', 'date', 'is_national']);

        foreach ($holidays as $holiday) {
            $date = substr((string) $holiday->date, 0, 10);

            if ($holiday->is_national || $holiday->company_id === null) {
                $global[$date] = true;
            } else {
                $perCompany[(int) $holiday->company_id][$date] = true;
            }
        }

        return new self($global, $perCompany);
    }

    /**
     * The last day on which a missing attendance may count as a no-show.
     */
    public static function lastCountableDay(): Carbon
    {
        return today()->subDay();
    }

    /**
     * @param  string  $date  Y-m-d
     * @param  bool|null  $scheduledDayOff  the worker's shift schedule for the date: true for a day off, false for a shift, null without a schedule
     * @param  string|null  $employedFrom  Y-m-d, the first day of employment
     * @param  string|null  $employedUntil  Y-m-d, the last day of employment
     * @param  bool  $rostered  the worker is on a roster on this date (from the week of their first shift schedule on), so only rostered days are working days
     */
    public function isExpected(string $date, ?bool $scheduledDayOff, ?int $companyId, ?string $employedFrom = null, ?string $employedUntil = null, bool $rostered = false): bool
    {
        if (($employedFrom !== null && $date < $employedFrom) || ($employedUntil !== null && $date > $employedUntil)) {
            return false;
        }

        if ($scheduledDayOff !== null) {
            return ! $scheduledDayOff;
        }

        if ($rostered) {
            return false;
        }

        if ($this->isHoliday($date, $companyId)) {
            return false;
        }

        return Carbon::parse($date)->isWeekday();
    }

    public function isHoliday(string $date, ?int $companyId): bool
    {
        return isset($this->globalHolidays[$date])
            || ($companyId !== null && isset($this->companyHolidays[$companyId][$date]));
    }
}
