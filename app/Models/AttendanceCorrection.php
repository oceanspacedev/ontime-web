<?php

namespace App\Models;

use App\Services\WorkScheduleService;
use App\Traits\ScopesToWorkerVisibility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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

    public function applyCorrection(): void
    {
        $search = ['date' => $this->date];
        if ($this->employee_id) {
            $search['employee_id'] = $this->employee_id;
        } elseif ($this->intern_id) {
            $search['intern_id'] = $this->intern_id;
        } elseif ($this->freelancer_id) {
            $search['freelancer_id'] = $this->freelancer_id;
        }

        $workSchedule = app(WorkScheduleService::class);
        $profile = $this->getWorkerProfile();
        $segment = $profile ? $this->segmentOfCorrection($workSchedule, $profile) : 1;

        $attendance = Attendance::firstOrNew([...$search, 'segment' => $segment]);

        if ($this->corrected_check_in) {
            $attendance->check_in = $this->corrected_check_in;
        }
        if ($this->corrected_check_out) {
            $attendance->check_out = $this->corrected_check_out;
        }

        $attendance->is_corrected = true;

        if ($this->corrected_check_in && $profile) {
            // Judge the corrected time against the hours of its own segment, so a split shift's
            // second segment is not measured from the first one.
            $hours = $workSchedule->resolve($profile, Carbon::parse($this->date), $segment);
            $lateMinutes = $workSchedule->lateMinutes($hours, Carbon::parse($this->corrected_check_in));
            $attendance->late_minutes = $lateMinutes;
            $attendance->status = $lateMinutes > 0 ? 'late' : 'on_time';
            $attendance->shift_id ??= $hours['shift']?->id;
        } elseif ($this->corrected_check_in && ! $attendance->status) {
            $attendance->status = 'on_time';
        }

        $attendance->save();

        $this->update([
            'status' => 'approved',
            'attendance_id' => $attendance->id,
        ]);
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

        return Carbon::parse($time)->greaterThan($middle) ? 2 : 1;
    }
}
