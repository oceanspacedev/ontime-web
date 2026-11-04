<?php

namespace App\Models;

use App\Traits\HasNormalizedPhone;
use App\Traits\PreventsDeletionWhileInUse;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory, HasNormalizedPhone, PreventsDeletionWhileInUse;

    protected $fillable = [
        'name',
        'code',
        'email',
        'phone',
        'address',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Company $company) {
            $company->policy()->create([
                'late_tolerance_minutes' => 15,
                'require_photo' => false,
                'require_gps' => false,
                'geofence_radius_meters' => 100,
                'annual_leave_quota' => 12,
                'default_approval_stages' => 1,
                'work_start_time' => '08:00:00',
                'work_end_time' => '17:00:00',
            ]);
        });
    }

    public function deletionBlockedReason(): ?string
    {
        if ($this->divisions()->exists() || $this->employees()->exists()) {
            return 'Tidak dapat menghapus badan usaha ini karena masih memiliki divisi atau karyawan yang terikat.';
        }

        return null;
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function policy(): HasOne
    {
        return $this->hasOne(CompanyPolicy::class);
    }

    public function approvalFlows(): HasMany
    {
        return $this->hasMany(ApprovalFlow::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(CompanyLocation::class);
    }
}
