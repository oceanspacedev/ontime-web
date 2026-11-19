<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Services\Face\FaceVerifier;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The attendance policy of a Badan Usaha: its options, the rules a change must pass and how it is
 * stored. The web panel (Kebijakan Absensi) and the app use this same class, so both always accept
 * and save exactly the same policy.
 */
final class CompanyPolicyService
{
    public const LIVENESS_DRIVER_MESSAGE = 'Liveness butuh CompreFace (FACE_DRIVER=compreface).';

    public const GPS_WITHOUT_LOCATION_MESSAGE = 'Wajib Lokasi GPS membutuhkan Koordinat GPS Utama atau minimal satu cabang aktif.';

    /** A full day: a larger tolerance would quietly switch lateness off for every worker. */
    public const MAX_LATE_TOLERANCE_MINUTES = 1440;

    public const MAX_ANNUAL_LEAVE_QUOTA = 365;

    public const MAX_APPROVAL_STAGES = 10;

    /** 50 km, for the company policy and for each outlet. */
    public const MAX_GEOFENCE_RADIUS_METERS = 50000;

    /**
     * The policy as the form shows it, with the defaults of a new company where nothing is stored.
     *
     * @return array{late_tolerance_minutes: int, require_gps: bool, geofence_radius_meters: int, mock_location_action: string, selfie_mode: string, face_match_threshold: int, face_fail_action: string, annual_leave_quota: int, default_approval_stages: int, overtime_netting_from: string|null, uses_shift_roster: bool}
     */
    public static function formState(Company $company): array
    {
        $policy = $company->policy;

        return [
            'late_tolerance_minutes' => (int) ($policy?->late_tolerance_minutes ?? 15),
            'require_gps' => (bool) ($policy?->require_gps ?? false),
            'geofence_radius_meters' => (int) ($policy?->geofence_radius_meters ?? 100),
            'mock_location_action' => $policy?->mock_location_action ?? 'reject',
            'selfie_mode' => $policy?->selfieMode() ?? 'none',
            'face_match_threshold' => (int) ($policy?->face_match_threshold ?? 60),
            'face_fail_action' => $policy?->face_fail_action ?? 'reject',
            'annual_leave_quota' => (int) ($policy?->annual_leave_quota ?? 12),
            'default_approval_stages' => (int) ($policy?->default_approval_stages ?? 1),
            'overtime_netting_from' => $policy?->overtime_netting_from?->toDateString(),
            'uses_shift_roster' => (bool) ($policy?->uses_shift_roster ?? false),
        ];
    }

    /**
     * Validation rules for a policy change sent as plain data (the app). The panel builds the same
     * checks from its form fields with gpsRule() and livenessRule().
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(Company $company): array
    {
        $usesFaceMatch = 'in:face,liveness';

        return [
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:'.self::MAX_LATE_TOLERANCE_MINUTES],
            'require_gps' => ['required', 'boolean', self::gpsRule($company)],
            'geofence_radius_meters' => ['nullable', 'required_if_accepted:require_gps', 'integer', 'min:1', 'max:'.self::MAX_GEOFENCE_RADIUS_METERS],
            'mock_location_action' => ['nullable', 'required_if_accepted:require_gps', Rule::in(array_keys(self::mockLocationActionOptions()))],
            'selfie_mode' => ['required', 'string', Rule::in(array_keys(self::selfieModeOptions())), self::livenessRule()],
            'face_match_threshold' => ['nullable', 'required_if:selfie_mode,face,liveness', 'integer', 'min:1', 'max:100'],
            'face_fail_action' => ['nullable', 'required_if:selfie_mode,face,liveness', Rule::in(array_keys(self::faceFailActionOptions()))],
            'annual_leave_quota' => ['required', 'integer', 'min:0', 'max:'.self::MAX_ANNUAL_LEAVE_QUOTA],
            'default_approval_stages' => ['required', 'integer', 'min:1', 'max:'.self::MAX_APPROVAL_STAGES],
            'work_start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'work_end_time' => ['sometimes', 'required', 'date_format:H:i'],
            // Left out by older apps: the stored date then stays as it is.
            'overtime_netting_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // Left out by older apps: the company keeps the mode it is in.
            'uses_shift_roster' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Indonesian messages for rules().
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'late_tolerance_minutes.required' => 'Toleransi keterlambatan wajib diisi.',
            'late_tolerance_minutes.min' => 'Toleransi keterlambatan tidak boleh negatif.',
            'late_tolerance_minutes.max' => 'Toleransi keterlambatan maksimal '.self::MAX_LATE_TOLERANCE_MINUTES.' menit.',
            'geofence_radius_meters.required_if_accepted' => 'Radius geofence wajib diisi jika GPS diwajibkan.',
            'geofence_radius_meters.min' => 'Radius geofence minimal 1 meter.',
            'geofence_radius_meters.max' => 'Radius geofence maksimal '.self::MAX_GEOFENCE_RADIUS_METERS.' meter.',
            'mock_location_action.required_if_accepted' => 'Pilih tindakan jika lokasi palsu terdeteksi.',
            'mock_location_action.in' => 'Tindakan lokasi palsu tidak dikenal.',
            'selfie_mode.required' => 'Pilih verifikasi selfie.',
            'selfie_mode.in' => 'Verifikasi selfie tidak dikenal.',
            'face_match_threshold.required_if' => 'Ambang kemiripan wajah wajib diisi.',
            'face_match_threshold.min' => 'Ambang kemiripan wajah antara 1 dan 100.',
            'face_match_threshold.max' => 'Ambang kemiripan wajah antara 1 dan 100.',
            'face_fail_action.required_if' => 'Pilih tindakan jika wajah tidak cocok.',
            'face_fail_action.in' => 'Tindakan wajah tidak cocok tidak dikenal.',
            'annual_leave_quota.required' => 'Kuota cuti tahunan wajib diisi.',
            'annual_leave_quota.min' => 'Kuota cuti tahunan tidak boleh negatif.',
            'annual_leave_quota.max' => 'Kuota cuti tahunan maksimal '.self::MAX_ANNUAL_LEAVE_QUOTA.' hari.',
            'default_approval_stages.required' => 'Tahap approval wajib diisi.',
            'default_approval_stages.min' => 'Tahap approval minimal 1.',
            'default_approval_stages.max' => 'Tahap approval maksimal '.self::MAX_APPROVAL_STAGES.'.',
            'work_start_time.date_format' => 'Jam masuk harus berformat JJ:MM.',
            'work_end_time.date_format' => 'Jam pulang harus berformat JJ:MM.',
            'overtime_netting_from.date_format' => 'Tanggal mulai pemotongan lembur harus berformat TTTT-BB-HH.',
            'uses_shift_roster.boolean' => 'Pilihan jadwal shift harus ya atau tidak.',
        ];
    }

    /**
     * GPS can only be required once the company has a HQ coordinate or an active location.
     */
    public static function gpsRule(?Company $company): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($company): void {
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && $company && ! GeofenceService::hasConfiguredLocations($company)) {
                $fail(static::GPS_WITHOUT_LOCATION_MESSAGE);
            }
        };
    }

    /**
     * Selfie mode "liveness" needs a face service that reads the head pose.
     */
    public static function livenessRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === 'liveness' && ! static::supportsLiveness()) {
                $fail(static::LIVENESS_DRIVER_MESSAGE);
            }
        };
    }

    /**
     * The old require_liveness flag needs the same face service as selfie mode "liveness".
     */
    public static function livenessFlagRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && ! static::supportsLiveness()) {
                $fail(static::LIVENESS_DRIVER_MESSAGE);
            }
        };
    }

    public static function supportsLiveness(): bool
    {
        return self::faceVerifier()->supportsPose();
    }

    /**
     * Store a validated policy change. Fields the form hides (radius and mock location action without
     * GPS, face threshold and action without face matching) keep their stored values.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(Company $company, array $data): CompanyPolicy
    {
        $attributes = self::normalize(self::preferLegacySelfieFlags($data));

        if (array_key_exists('require_gps', $attributes) && ! filter_var($attributes['require_gps'], FILTER_VALIDATE_BOOLEAN)) {
            unset($attributes['geofence_radius_meters'], $attributes['mock_location_action']);
        }

        if (! $attributes['require_face_recognition']) {
            unset($attributes['face_match_threshold'], $attributes['face_fail_action']);
        }

        $attributes = array_filter($attributes, fn (mixed $value, string $key): bool => $value !== null || ! in_array($key, ['geofence_radius_meters', 'mock_location_action', 'face_match_threshold', 'face_fail_action'], true), ARRAY_FILTER_USE_BOTH);

        $policy = $company->policy()->updateOrCreate(['company_id' => $company->id], $attributes);
        $company->setRelation('policy', $policy);

        return $policy;
    }

    /**
     * Turn the selfie mode into the stored requirement flags.
     *
     * Older callers that still send `require_photo`, `require_face_recognition` or `require_liveness`
     * instead of `selfie_mode` keep working: the strictest flag wins, so face recognition always
     * implies a photo and liveness always implies face recognition.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $mode = $data['selfie_mode'] ?? null;

        if (! is_string($mode) || ! array_key_exists($mode, self::selfieModeOptions())) {
            $mode = match (true) {
                ! empty($data['require_liveness']) => 'liveness',
                ! empty($data['require_face_recognition']) => 'face',
                ! empty($data['require_photo']) => 'photo',
                default => 'none',
            };
        }

        unset($data['selfie_mode']);

        return [...$data, ...CompanyPolicy::flagsForSelfieMode($mode)];
    }

    /**
     * Let the old requirement flags decide the selfie mode when a caller sent them.
     *
     * The form always fills `selfie_mode`, so without this an older caller that only sends
     * `require_photo` / `require_face_recognition` / `require_liveness` would silently save the
     * filled mode instead of the flags it asked for.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function preferLegacySelfieFlags(array $data): array
    {
        $legacyKeys = ['require_photo', 'require_face_recognition', 'require_liveness'];
        $sentLegacyFlags = array_filter(
            array_intersect_key($data, array_flip($legacyKeys)),
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if ($sentLegacyFlags === []) {
            return array_diff_key($data, array_flip($legacyKeys));
        }

        unset($data['selfie_mode']);

        foreach ($legacyKeys as $key) {
            $data[$key] = filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public static function selfieModeOptions(): array
    {
        return [
            'none' => 'Tanpa foto (klik saja)',
            'photo' => 'Foto selfie',
            'face' => 'Foto + cocokkan dengan foto master wajah',
            'liveness' => 'Liveness (gerak kepala) + cocokkan wajah',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mockLocationActionOptions(): array
    {
        return [
            'reject' => 'Tolak presensi',
            'approval' => 'Alihkan ke persetujuan atasan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function faceFailActionOptions(): array
    {
        return [
            'reject' => 'Tolak Presensi Langsung',
            'approval' => 'Alihkan ke Approval Atasan (Pending)',
        ];
    }

    /**
     * Warnings for modes the configured face service cannot do properly; other modes need no description.
     *
     * @return array<string, string>
     */
    public static function selfieModeWarnings(): array
    {
        $verifier = self::faceVerifier();

        return array_filter([
            'face' => $verifier->isAccurate() ? null : 'Pembanding bawaan, bukan pengenalan wajah sungguhan.',
            'liveness' => $verifier->supportsPose() ? null : 'Butuh CompreFace.',
        ]);
    }

    /**
     * Every selfie mode with a short explanation and whether the face service can do it (the app).
     *
     * @return list<array{value: string, label: string, description: string, available: bool}>
     */
    public static function selfieModeChoices(): array
    {
        $warnings = self::selfieModeWarnings();
        $descriptions = [
            'none' => 'Pekerja cukup menekan tombol absen.',
            'photo' => 'Pekerja mengambil foto selfie saat absen.',
            'face' => 'Selfie dicocokkan dengan foto master wajah pekerja.',
            'liveness' => 'Pekerja menggerakkan kepala sesuai instruksi, lalu wajah dicocokkan.',
        ];

        return collect(self::selfieModeOptions())
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
                'description' => $warnings[$value] ?? $descriptions[$value],
                'available' => $value !== 'liveness' || self::supportsLiveness(),
            ])
            ->values()
            ->all();
    }

    public static function usesFaceMatch(mixed $selfieMode): bool
    {
        return in_array($selfieMode, ['face', 'liveness'], true);
    }

    private static function faceVerifier(): FaceVerifier
    {
        return app(FaceVerifier::class);
    }
}
