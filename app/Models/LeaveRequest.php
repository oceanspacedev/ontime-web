<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Traits\ScopesToWorkerVisibility;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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

    public function applyLeave(): void
    {
        $period = CarbonPeriod::create($this->start_date, $this->end_date);

        // Build the FK condition for attendance records
        $workerCondition = array_filter([
            'employee_id' => $this->employee_id,
            'intern_id' => $this->intern_id,
            'freelancer_id' => $this->freelancer_id,
        ]);

        foreach ($period as $date) {
            Attendance::updateOrCreate(
                array_merge($workerCondition, ['date' => $date->toDateString()]),
                [
                    'status' => AttendanceStatus::Leave->value,
                    'notes' => 'Cuti / Izin Disetujui: '.$this->reason,
                ]
            );
        }
    }
}
