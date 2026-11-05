<?php

namespace App\Models;

use App\Traits\HasNormalizedPhone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory, HasNormalizedPhone;

    protected $fillable = [
        'user_id',
        'company_id',
        'division_id',
        'shift_id',
        'job_level_id',
        'job_title_id',
        'nip',
        'talenta_id',
        'full_name',
        'email',
        'phone',
        'gender',
        'birth_date',
        'marital_status',
        'tax_status',
        'avatar_url',
        'join_date',
        'has_annual_leave',
        'annual_leave_quota',
        'status',
        'master_face_photo',
        'master_face_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'has_annual_leave' => 'boolean',
            'annual_leave_quota' => 'integer',
            'birth_date' => 'date',
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

    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
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

    public function attendanceCorrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
    }

    public function mentoredInterns(): HasMany
    {
        return $this->hasMany(Intern::class, 'mentor_id');
    }

    public function supervisedFreelancers(): HasMany
    {
        return $this->hasMany(Freelancer::class, 'supervisor_id');
    }

    public function resignations(): HasMany
    {
        return $this->hasMany(Resignation::class);
    }
}
