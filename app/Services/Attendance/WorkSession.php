<?php

namespace App\Services\Attendance;

use App\Models\CompanyLocation;
use App\Models\Shift;
use App\Services\WorkScheduleService;
use Carbon\CarbonImmutable;

/**
 * One stretch of work a worker is planned for: one part of a working schedule (a normal shift has one,
 * a split shift two), or the standing hours of a worker without a dated schedule (default shift, or the
 * company hours). It always belongs to the business date it starts on, also when it ends after midnight.
 */
final readonly class WorkSession
{
    /**
     * @param  CarbonImmutable  $date  business date (start of day)
     * @param  int  $sequence  per-day number of the schedule row (1 for standing hours)
     * @param  int  $segment  part of the schedule: 1, or 2 for the second part of a split shift
     * @param  int  $scheduledMinutes  minutes of the whole schedule, all parts together
     * @param  int|null  $normalMinutes  normal minutes of the whole schedule; null = no automatic overtime
     * @param  string  $source  schedule|default|policy|cover
     * @param  bool  $isExpected  false for standing hours on a weekend or a holiday (the worker may come in, but is not absent otherwise)
     * @param  bool  $isPlacement  standing hours planned by a legacy placement row (an outlet without a shift, in a company
     *                             without shift rosters): they apply to a freelancer without a default shift too
     */
    public function __construct(
        public CarbonImmutable $date,
        public int $sequence,
        public int $segment,
        public int $segmentCount,
        public CarbonImmutable $startAt,
        public CarbonImmutable $endAt,
        public int $toleranceMinutes,
        public int $scheduledMinutes,
        public ?int $normalMinutes,
        public string $source,
        public ?int $scheduleId = null,
        public ?Shift $shift = null,
        public ?CompanyLocation $location = null,
        public ?string $section = null,
        public bool $isExpected = true,
        public bool $isPlacement = false,
    ) {}

    /**
     * "sequence:segment", the key of the attendance row that belongs to this session.
     */
    public function key(): string
    {
        return $this->sequence.':'.$this->segment;
    }

    /**
     * Whether the session ends on a later calendar day than it starts.
     */
    public function crossesMidnight(): bool
    {
        return ! $this->endAt->isSameDay($this->startAt);
    }

    /**
     * The session in the array shape of {@see WorkScheduleService::resolve()}.
     *
     * @return array{shift: Shift|null, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string, location: CompanyLocation|null, section: string|null, segment: int, segment_count: int, schedule_id: int|null, sequence: int, start_at: CarbonImmutable, end_at: CarbonImmutable, scheduled_minutes: int, normal_minutes: int|null, is_placement: bool}
     */
    public function toWorkingHours(): array
    {
        return [
            'shift' => $this->shift,
            'is_day_off' => false,
            'start_time' => $this->startAt->format('H:i:s'),
            'end_time' => $this->endAt->format('H:i:s'),
            'late_tolerance_minutes' => $this->toleranceMinutes,
            'source' => $this->source,
            'location' => $this->location,
            'section' => $this->section,
            'segment' => $this->segment,
            'segment_count' => $this->segmentCount,
            'schedule_id' => $this->scheduleId,
            'sequence' => $this->sequence,
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'scheduled_minutes' => $this->scheduledMinutes,
            'normal_minutes' => $this->normalMinutes,
            'is_placement' => $this->isPlacement,
        ];
    }
}
