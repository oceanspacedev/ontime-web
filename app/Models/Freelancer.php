<?php

namespace App\Models;

use App\Traits\HasNormalizedPhone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Freelancer extends Model
{
    use HasFactory, HasNormalizedPhone;

    protected $fillable = [
        'user_id',
        'company_id',
        'division_id',
        'shift_id',
        'supervisor_id',
        'freelancer_number',
        'full_name',
        'institution',
        'email',
        'phone',
        'start_date',
        'end_date',
        'status',
        'master_face_photo',
        'master_face_verified_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Freelancer $freelancer): void {
            if (blank($freelancer->freelancer_number)) {
                $freelancer->freelancer_number = static::nextNumber($freelancer->company_id);
            }
        });
    }

    /**
     * Next free number for a company, "FL-CODE-0001".
     */
    public static function nextNumber(int|string|null $companyId): string
    {
        $code = Str::upper(Str::slug((string) Company::query()->whereKey($companyId)->value('code'), '')) ?: 'X';
        $prefix = "FL-{$code}-";

        $last = static::query()
            ->where('freelancer_number', 'like', $prefix.'%')
            ->pluck('freelancer_number')
            ->map(fn (string $number): int => (int) Str::after($number, $prefix))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'master_face_verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Default shift; a dated ShiftSchedule overrides it.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }
}
