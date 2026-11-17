<?php

namespace App\Models;

use App\Traits\ScopesToWorkerVisibility;
use Carbon\CarbonInterface;
use Database\Factories\ShiftSwapRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Tukar Shift. The requester (employee_id / intern_id / freelancer_id) asks a colleague of the same company
 * (the counterpart) to trade their schedules:
 *
 * - swap: on requester_date and counterpart_date (the same date for a same-day swap) the two workers trade
 *   whatever they were scheduled for; "kamu ambil Selasa saya, saya ambil Kamis kamu".
 * - give_away: the counterpart, off that day, takes the requester's shift on requester_date and the requester
 *   gets the day off.
 *
 * Lifecycle: awaiting_colleague → (colleague accepts) pending → approved; or declined (by the colleague),
 * rejected (by an approver), cancelled (by the requester), expired (a date passed) or failed (the schedules
 * changed or someone checked in before the final approval).
 */
class ShiftSwapRequest extends Model
{
    /** @use HasFactory<ShiftSwapRequestFactory> */
    use HasFactory, ScopesToWorkerVisibility;

    public const TYPE_SWAP = 'swap';

    public const TYPE_GIVE_AWAY = 'give_away';

    public const STATUS_AWAITING_COLLEAGUE = 'awaiting_colleague';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    /**
     * Statuses of a request that is still open: waiting for the colleague or for an approver.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = [self::STATUS_AWAITING_COLLEAGUE, self::STATUS_PENDING];

    /**
     * Labels shown in the panel and the app.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_AWAITING_COLLEAGUE => 'Menunggu Rekan',
        self::STATUS_PENDING => 'Menunggu Persetujuan',
        self::STATUS_APPROVED => 'Disetujui',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_DECLINED => 'Ditolak Rekan',
        self::STATUS_CANCELLED => 'Dibatalkan',
        self::STATUS_EXPIRED => 'Kedaluwarsa',
        self::STATUS_FAILED => 'Gagal Diterapkan',
    ];

    protected $fillable = [
        'company_id',
        'employee_id',
        'intern_id',
        'freelancer_id',
        'counterpart_employee_id',
        'counterpart_intern_id',
        'counterpart_freelancer_id',
        'type',
        'requester_date',
        'counterpart_date',
        'reason',
        'schedule_snapshot',
        'status',
        'current_step',
        'rejection_reason',
        'failure_reason',
        'counterpart_responded_at',
        'cancelled_at',
        'applied_at',
        'applied_by',
    ];

    protected function casts(): array
    {
        return [
            'requester_date' => 'date',
            'counterpart_date' => 'date',
            'schedule_snapshot' => 'array',
            'current_step' => 'integer',
            'counterpart_responded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    public function counterpartEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'counterpart_employee_id');
    }

    public function counterpartIntern(): BelongsTo
    {
        return $this->belongsTo(Intern::class, 'counterpart_intern_id');
    }

    public function counterpartFreelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'counterpart_freelancer_id');
    }

    public function approvalSteps(): MorphMany
    {
        return $this->morphMany(ApprovalRequestStep::class, 'approvable');
    }

    /**
     * The requester: approval flows, data access and notifications follow this worker.
     */
    public function getWorkerProfile(): Employee|Intern|Freelancer|null
    {
        return $this->employee ?? $this->intern ?? $this->freelancer;
    }

    public function counterpartProfile(): Employee|Intern|Freelancer|null
    {
        return $this->counterpartEmployee ?? $this->counterpartIntern ?? $this->counterpartFreelancer;
    }

    public function isGiveAway(): bool
    {
        return $this->type === self::TYPE_GIVE_AWAY;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * The dates whose schedules change, earliest first: one for a same-day swap or a give away, two otherwise.
     *
     * @return list<string>
     */
    public function affectedDates(): array
    {
        return collect([$this->requester_date, $this->counterpart_date])
            ->filter()
            ->map(fn (CarbonInterface $date): string => $date->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Whether the account is the requester of this request.
     */
    public function isRequestedBy(User $user): bool
    {
        $profile = $this->getWorkerProfile();

        return $profile?->user_id !== null && (int) $profile->user_id === (int) $user->id;
    }

    /**
     * Whether the account is the colleague asked to take part.
     */
    public function isCounterpart(User $user): bool
    {
        $profile = $this->counterpartProfile();

        return $profile?->user_id !== null && (int) $profile->user_id === (int) $user->id;
    }

    /**
     * Requests the account sent or received.
     *
     * @param  Builder<ShiftSwapRequest>  $query
     */
    public function scopeInvolving(Builder $query, User $user, string $box = 'all'): Builder
    {
        $sent = fn (Builder $mine) => $mine
            ->whereHas('employee', fn (Builder $worker) => $worker->where('user_id', $user->id))
            ->orWhereHas('intern', fn (Builder $worker) => $worker->where('user_id', $user->id))
            ->orWhereHas('freelancer', fn (Builder $worker) => $worker->where('user_id', $user->id));

        $received = fn (Builder $mine) => $mine
            ->whereHas('counterpartEmployee', fn (Builder $worker) => $worker->where('user_id', $user->id))
            ->orWhereHas('counterpartIntern', fn (Builder $worker) => $worker->where('user_id', $user->id))
            ->orWhereHas('counterpartFreelancer', fn (Builder $worker) => $worker->where('user_id', $user->id));

        return match ($box) {
            'sent' => $query->where($sent),
            'received' => $query->where($received),
            default => $query->where(fn (Builder $either) => $either->where($sent)->orWhere($received)),
        };
    }

    /**
     * Whether an affected date has passed, so the swap can no longer be applied.
     */
    public function isOverdue(): bool
    {
        $today = today()->toDateString();

        return collect($this->affectedDates())->contains(fn (string $date): bool => $date < $today);
    }

    /**
     * The status as it should be shown: an open request whose date passed reads as expired even before the
     * shift-swaps:expire command saved it.
     */
    public function effectiveStatus(): string
    {
        return $this->isOpen() && $this->isOverdue() ? self::STATUS_EXPIRED : (string) $this->status;
    }

    /**
     * Open requests whose dates have passed (what shift-swaps:expire closes).
     *
     * @param  Builder<ShiftSwapRequest>  $query
     */
    public function scopeOverdue(Builder $query): Builder
    {
        $today = today()->toDateString();

        return $query
            ->whereIn('status', self::OPEN_STATUSES)
            ->where(fn (Builder $dates) => $dates->whereDate('requester_date', '<', $today)->orWhereDate('counterpart_date', '<', $today));
    }

    /**
     * Requests whose dates are all today or later. Keeps overdue requests out of the approval inbox without a
     * write on every read.
     *
     * @param  Builder<ShiftSwapRequest>  $query
     */
    public function scopeNotOverdue(Builder $query): Builder
    {
        $today = today()->toDateString();

        return $query
            ->whereDate('requester_date', '>=', $today)
            ->where(fn (Builder $date) => $date->whereNull('counterpart_date')->orWhereDate('counterpart_date', '>=', $today));
    }

    public function statusLabel(): string
    {
        $status = $this->effectiveStatus();

        return self::STATUS_LABELS[$status] ?? $status;
    }
}
