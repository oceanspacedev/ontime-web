<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'late_tolerance_minutes',
        'require_photo',
        'require_gps',
        'require_face_recognition',
        'face_match_threshold',
        'face_fail_action',
        'require_liveness',
        'mock_location_action',
        'geofence_latitude',
        'geofence_longitude',
        'geofence_radius_meters',
        'annual_leave_quota',
        'default_approval_stages',
        'work_start_time',
        'work_end_time',
    ];

    protected function casts(): array
    {
        return [
            'require_photo' => 'boolean',
            'require_gps' => 'boolean',
            'require_face_recognition' => 'boolean',
            'face_match_threshold' => 'integer',
            'require_liveness' => 'boolean',
            'geofence_latitude' => 'decimal:7',
            'geofence_longitude' => 'decimal:7',
            'late_tolerance_minutes' => 'integer',
            'geofence_radius_meters' => 'integer',
            'annual_leave_quota' => 'integer',
            'default_approval_stages' => 'integer',
        ];
    }

    /**
     * Keep the selfie requirements consistent: liveness needs face matching and face matching needs a photo.
     */
    protected static function booted(): void
    {
        static::saving(function (CompanyPolicy $policy): void {
            if ($policy->require_liveness) {
                $policy->require_face_recognition = true;
            }

            if ($policy->require_face_recognition) {
                $policy->require_photo = true;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The strictest selfie check this policy asks for.
     *
     * @return 'none'|'photo'|'face'|'liveness'
     */
    public function selfieMode(): string
    {
        return match (true) {
            (bool) $this->require_liveness => 'liveness',
            (bool) $this->require_face_recognition => 'face',
            (bool) $this->require_photo => 'photo',
            default => 'none',
        };
    }

    /**
     * The stored requirement flags for a selfie mode.
     *
     * @return array{require_photo: bool, require_face_recognition: bool, require_liveness: bool}
     */
    public static function flagsForSelfieMode(string $mode): array
    {
        return [
            'require_photo' => in_array($mode, ['photo', 'face', 'liveness'], true),
            'require_face_recognition' => in_array($mode, ['face', 'liveness'], true),
            'require_liveness' => $mode === 'liveness',
        ];
    }

    /**
     * Short Indonesian description of the attendance mode, e.g. "Klik saja" or "GPS + Liveness + Wajah".
     */
    public function modeLabel(): string
    {
        $parts = [];

        if ($this->require_gps) {
            $parts[] = 'GPS';
        }

        $parts = [...$parts, ...match ($this->selfieMode()) {
            'liveness' => ['Liveness', 'Wajah'],
            'face' => ['Foto', 'Wajah'],
            'photo' => ['Foto'],
            default => [],
        }];

        return $parts === [] ? 'Klik saja' : implode(' + ', $parts);
    }
}
