<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceCheckResult;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\Face\FaceMatch;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;
use App\Support\SelfieUploadToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The single rule set for check-in and check-out, shared by the mobile API and the web panel.
 *
 * The company policy decides the mode: GPS (geofence and mock location) on or off, and a selfie
 * mode of none, photo, face or liveness. Refusals are thrown as {@see AttendanceRuleException};
 * soft failures (outside the office, fake location or face mismatch when the policy allows it)
 * are recorded and sent to the approver as a pending approval.
 */
class AttendanceCheckService
{
    public const PURPOSE_CHECK_IN = 'check_in';

    public const PURPOSE_CHECK_OUT = 'check_out';

    private const DEFAULT_FACE_THRESHOLD = 60;

    public function __construct(
        private readonly WorkScheduleService $workSchedule,
        private readonly ApprovalFlowService $approvalFlow,
    ) {}

    /**
     * Start a liveness challenge for a worker whose company requires liveness.
     *
     * @return array{challenge_id: string, steps: list<array{key: string, label: string, instruction: string}>, expires_at: string, expires_in: int}
     *
     * @throws AttendanceRuleException
     */
    public function livenessChallenge(User $user, string $purpose): array
    {
        $profile = $this->profileOf($user);

        if (! in_array($purpose, LivenessService::PURPOSES, true)) {
            throw new AttendanceRuleException('Jenis presensi tidak dikenali.', 422, 'purpose');
        }

        if ($profile->company?->policy?->selfieMode() !== 'liveness') {
            throw new AttendanceRuleException('Liveness tidak diwajibkan untuk badan usaha Anda.', 422, 'purpose');
        }

        try {
            return app(LivenessService::class)->issueChallenge($user, $purpose);
        } catch (FaceServiceUnavailable $exception) {
            throw new AttendanceRuleException($exception->getMessage(), 503, 'purpose');
        }
    }

    /**
     * The uploads in $input are owned by this call: a refused attempt removes them, and a successful
     * one removes those the saved record does not use (for example a photo sent in liveness mode).
     *
     * @throws AttendanceRuleException
     */
    public function checkIn(User $user, AttendanceCheckInput $input): AttendanceCheckResult
    {
        return $this->withUploadCleanup($user, $input, fn (): AttendanceCheckResult => $this->performCheckIn($user, $input));
    }

    /**
     * The uploads in $input are owned by this call, see {@see self::checkIn()}.
     *
     * @throws AttendanceRuleException
     */
    public function checkOut(User $user, AttendanceCheckInput $input): AttendanceCheckResult
    {
        return $this->withUploadCleanup($user, $input, fn (): AttendanceCheckResult => $this->performCheckOut($user, $input));
    }

    /**
     * Delete uploaded files of an attempt, except files a stored record still uses.
     *
     * A web upload token stays valid for {@see SelfieUploadToken::VALID_FOR_MINUTES} minutes and can be
     * submitted again, so a path may already be evidence of an earlier check (for example yesterday's
     * check-in photo when the token is replayed after midnight) or someone's master face photo (an
     * admin uploads it with their own token). Such paths are always kept.
     *
     * @param  array<int, string|null>  $paths
     */
    public function discardUploads(User $user, array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths, filled(...))));

        if ($paths === []) {
            return;
        }

        $unused = array_values(array_diff($paths, $this->pathsInUse($user, $paths)));

        if ($unused !== []) {
            Storage::disk(config('filesystems.default'))->delete($unused);
        }
    }

    /**
     * The given paths that are a master face photo of any worker, or a photo or liveness frame of
     * any attendance of this worker, on any date.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function pathsInUse(User $user, array $paths): array
    {
        $inUse = [];

        foreach ([Employee::class, Intern::class, Freelancer::class] as $profileModel) {
            array_push($inUse, ...$profileModel::query()->whereIn('master_face_photo', $paths)->pluck('master_face_photo')->all());
        }

        $attendances = Attendance::byWorker($user)
            ->where(function ($query) use ($paths): void {
                $query->whereIn('check_in_photo', $paths)
                    ->orWhereIn('check_out_photo', $paths);

                foreach ($paths as $path) {
                    $query->orWhereJsonContains('liveness_frames', $path);
                }
            })
            ->get(['check_in_photo', 'check_out_photo', 'liveness_frames']);

        foreach ($attendances as $attendance) {
            array_push($inUse, $attendance->check_in_photo, $attendance->check_out_photo, ...($attendance->liveness_frames ?? []));
        }

        return array_values(array_intersect($paths, array_filter($inUse)));
    }

    /**
     * @param  callable(): AttendanceCheckResult  $check
     *
     * @throws AttendanceRuleException
     */
    private function withUploadCleanup(User $user, AttendanceCheckInput $input, callable $check): AttendanceCheckResult
    {
        $uploads = [$input->photoPath, ...$input->livenessFramePaths];

        try {
            $result = $check();
        } catch (AttendanceRuleException $exception) {
            $this->discardUploads($user, $uploads);

            throw $exception;
        }

        $this->discardUploads($user, $uploads);

        return $result;
    }

    /**
     * @throws AttendanceRuleException
     */
    private function performCheckIn(User $user, AttendanceCheckInput $input): AttendanceCheckResult
    {
        $profile = $this->profileOf($user);
        $existingAttendance = Attendance::byWorker($user)->today()->first();

        if ($existingAttendance?->check_in) {
            throw new AttendanceRuleException('Sudah melakukan check in hari ini.', 409);
        }

        $workingHours = $this->workSchedule->resolve($profile, now());
        $checks = $this->runChecks($user, $profile, $input, self::PURPOSE_CHECK_IN, $workingHours['location']);
        $reasons = $checks['approval_reasons'];
        $requiresApproval = $reasons !== [];

        $now = now();
        $lateMinutes = $requiresApproval ? 0 : $this->workSchedule->lateMinutes($workingHours, $now);
        $status = match (true) {
            $requiresApproval => AttendanceStatus::PendingApproval,
            $lateMinutes > 0 => AttendanceStatus::Late,
            default => AttendanceStatus::OnTime,
        };

        $attendance = DB::transaction(function () use ($user, $existingAttendance, $checks, $workingHours, $now, $status, $lateMinutes, $requiresApproval): Attendance {
            // Reuse today's row (for example one created by an approved correction) instead of
            // inserting a second row, which the unique (worker, date) index rejects.
            $attendance = $existingAttendance ?? new Attendance([
                ...Attendance::workerKeys($user),
                'date' => today(),
            ]);

            $attendance->fill([
                'shift_id' => $workingHours['shift']?->id,
                'company_location_id' => $workingHours['location']?->id,
                'check_in' => $now,
                'check_in_lat' => $checks['latitude'],
                'check_in_lng' => $checks['longitude'],
                'check_in_photo' => $checks['photo_path'],
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'is_out_of_bounds' => $checks['is_out_of_bounds'],
                'is_mock_location' => $checks['is_mock_location'],
                'is_face_verified' => $checks['is_face_verified'],
                'face_match_score' => $checks['face_match_score'],
                'face_verification_notes' => $checks['face_verification_notes'],
                'liveness_verified' => $checks['liveness_verified'],
                'liveness_notes' => $checks['liveness_notes'],
                'liveness_frames' => $checks['liveness_frames'],
            ])->save();

            if ($requiresApproval) {
                $this->approvalFlow->generateSteps($attendance, 'geofence');
            }

            return $attendance;
        });

        return new AttendanceCheckResult(
            attendance: $attendance->fresh() ?? $attendance,
            requiresApproval: $requiresApproval,
            approvalReasons: $reasons,
            message: $this->message('Check in', $reasons),
        );
    }

    /**
     * @throws AttendanceRuleException
     */
    private function performCheckOut(User $user, AttendanceCheckInput $input): AttendanceCheckResult
    {
        $profile = $this->profileOf($user);
        $attendance = Attendance::byWorker($user)->today()->first();

        if (! $attendance?->check_in) {
            throw new AttendanceRuleException('Anda belum melakukan check in hari ini.', 422);
        }

        if ($attendance->check_out) {
            throw new AttendanceRuleException('Sudah melakukan check out hari ini.', 409);
        }

        $checks = $this->runChecks($user, $profile, $input, self::PURPOSE_CHECK_OUT, $attendance->location);
        $reasons = $checks['approval_reasons'];
        $requiresApproval = $reasons !== [];

        $updates = [
            'check_out' => now(),
            'check_out_lat' => $checks['latitude'],
            'check_out_lng' => $checks['longitude'],
            'check_out_photo' => $checks['photo_path'],
            'is_out_of_bounds' => $checks['is_out_of_bounds'] || $attendance->is_out_of_bounds,
            'is_mock_location' => $checks['is_mock_location'] || $attendance->is_mock_location,
            'is_face_verified' => $checks['is_face_verified'] ?? $attendance->is_face_verified,
            'face_match_score' => $checks['face_match_score'] ?? $attendance->face_match_score,
            'face_verification_notes' => $checks['face_verification_notes'] ?? $attendance->face_verification_notes,
            'liveness_verified' => $checks['liveness_verified'] ?? $attendance->liveness_verified,
            'liveness_notes' => $checks['liveness_notes'] ?? $attendance->liveness_notes,
            'liveness_frames' => $checks['liveness_frames'] ?? $attendance->liveness_frames,
        ];

        if ($requiresApproval) {
            $updates['status'] = AttendanceStatus::PendingApproval;
        }

        DB::transaction(function () use ($attendance, $updates, $requiresApproval): void {
            $attendance->update($updates);

            if ($requiresApproval && $attendance->needsNewApprovalRound()) {
                $this->approvalFlow->generateSteps($attendance, 'geofence');
            }
        });

        return new AttendanceCheckResult(
            attendance: $attendance->fresh() ?? $attendance,
            requiresApproval: $requiresApproval,
            approvalReasons: $reasons,
            message: $this->message('Check out', $reasons),
        );
    }

    /**
     * @throws AttendanceRuleException
     */
    private function profileOf(User $user): Employee|Intern|Freelancer
    {
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            throw new AttendanceRuleException('Profil pengguna tidak ditemukan.', 422);
        }

        return $profile;
    }

    /**
     * Apply the location and selfie rules of the worker's company policy. With a scheduled outlet,
     * the geofence only accepts that outlet.
     *
     * @return array{
     *     latitude: float|null,
     *     longitude: float|null,
     *     photo_path: string|null,
     *     is_out_of_bounds: bool,
     *     is_mock_location: bool,
     *     is_face_verified: bool|null,
     *     face_match_score: int|null,
     *     face_verification_notes: string|null,
     *     liveness_verified: bool|null,
     *     liveness_notes: string|null,
     *     liveness_frames: list<string>|null,
     *     approval_reasons: list<string>,
     * }
     *
     * @throws AttendanceRuleException
     */
    private function runChecks(User $user, Employee|Intern|Freelancer $profile, AttendanceCheckInput $input, string $purpose, ?CompanyLocation $outlet = null): array
    {
        $company = $profile->company;
        $policy = $company?->policy;
        $reasons = [];

        $checks = [
            'latitude' => $input->latitude,
            'longitude' => $input->longitude,
            'photo_path' => $input->photoPath,
            'is_out_of_bounds' => false,
            'is_mock_location' => $input->isMocked,
            'is_face_verified' => null,
            'face_match_score' => null,
            'face_verification_notes' => null,
            'liveness_verified' => null,
            'liveness_notes' => null,
            'liveness_frames' => null,
        ];

        if ($policy?->require_gps) {
            if ($input->latitude === null || $input->longitude === null) {
                throw new AttendanceRuleException('Lokasi GPS wajib diisi.', 422, 'latitude');
            }

            if ($input->isMocked) {
                if ($policy->mock_location_action !== 'approval') {
                    throw new AttendanceRuleException(
                        'Lokasi palsu (mock location) terdeteksi. Matikan aplikasi pemalsu lokasi lalu coba lagi.',
                        422,
                        'latitude',
                    );
                }

                $reasons[] = 'lokasi palsu terdeteksi';
            }

            $geofence = GeofenceService::validateCompanyGeofence($company, $input->latitude, $input->longitude, $outlet);

            if (! $geofence['is_valid']) {
                $checks['is_out_of_bounds'] = true;
                $reasons[] = $outlet ? "di luar area outlet jadwal ({$outlet->name})" : 'di luar area kantor';
            }
        }

        $selfieMode = $policy?->selfieMode() ?? 'none';

        $masterPhoto = $profile->master_face_photo;
        $submittedPaths = array_filter([$input->photoPath, ...$input->livenessFramePaths]);

        if (filled($masterPhoto) && in_array($masterPhoto, $submittedPaths, true)) {
            throw new AttendanceRuleException(SelfieUploadToken::INVALID_MESSAGE, 422, 'photo');
        }

        if (in_array($selfieMode, ['photo', 'face'], true) && blank($input->photoPath)) {
            throw new AttendanceRuleException('Foto selfie absensi wajib diunggah.', 422, 'photo');
        }

        if (! in_array($selfieMode, ['face', 'liveness'], true)) {
            return [...$checks, 'approval_reasons' => $reasons];
        }

        if (blank($profile->master_face_photo)) {
            throw new AttendanceRuleException(
                'Foto Master Wajah belum didaftarkan. Silakan daftarkan Foto Master Wajah Anda terlebih dahulu.',
                422,
                'photo',
            );
        }

        try {
            if ($selfieMode === 'liveness') {
                $faceMatch = $this->verifyLiveness($user, $profile, $policy, $input, $purpose, $checks);
            } else {
                $faceMatch = $this->faceVerifier()->verify($input->photoPath, $profile->master_face_photo, $this->threshold($policy));
            }
        } catch (FaceServiceUnavailable) {
            throw new AttendanceRuleException('Layanan verifikasi wajah sedang tidak tersedia. Coba lagi beberapa saat.', 503, 'photo');
        }

        $checks['is_face_verified'] = $faceMatch->matched;
        $checks['face_match_score'] = $faceMatch->score;
        $checks['face_verification_notes'] = $faceMatch->notes;

        if (! $faceMatch->matched) {
            if ($policy->face_fail_action !== 'approval') {
                throw new AttendanceRuleException('Verifikasi wajah gagal: '.$faceMatch->notes, 422, 'photo');
            }

            $reasons[] = 'verifikasi wajah tidak cocok';
        }

        return [...$checks, 'approval_reasons' => $reasons];
    }

    /**
     * Run the liveness challenge and return the face match of its front frame.
     *
     * The front frame becomes the attendance photo; the liveness fields are written into $checks.
     *
     * @param  array<string, mixed>  $checks
     *
     * @throws AttendanceRuleException
     * @throws FaceServiceUnavailable
     */
    private function verifyLiveness(
        User $user,
        Employee|Intern|Freelancer $profile,
        CompanyPolicy $policy,
        AttendanceCheckInput $input,
        string $purpose,
        array &$checks,
    ): FaceMatch {
        if (blank($input->livenessChallengeId) || $input->livenessFramePaths === []) {
            throw new AttendanceRuleException(
                'Verifikasi liveness wajib. Ambil foto sesuai langkah yang diminta.',
                422,
                'liveness_frames',
            );
        }

        $result = app(LivenessService::class)->verify(
            $user,
            $input->livenessChallengeId,
            $purpose,
            array_values($input->livenessFramePaths),
            $profile->master_face_photo,
            $policy,
        );

        if (! $result->livenessPassed) {
            throw new AttendanceRuleException($result->notes, 422, 'liveness_frames');
        }

        $checks['photo_path'] = $result->frontFramePath;
        $checks['liveness_verified'] = true;
        $checks['liveness_notes'] = $result->notes;
        $checks['liveness_frames'] = array_values($input->livenessFramePaths);

        return $result->faceMatch
            ?? $this->faceVerifier()->verify($result->frontFramePath, $profile->master_face_photo, $this->threshold($policy));
    }

    private function faceVerifier(): FaceVerifier
    {
        return app(FaceVerifier::class);
    }

    private function threshold(CompanyPolicy $policy): int
    {
        return $policy->face_match_threshold ?? self::DEFAULT_FACE_THRESHOLD;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function message(string $action, array $reasons): string
    {
        if ($reasons === []) {
            return "{$action} berhasil";
        }

        return "{$action} berhasil dicatat dan sedang menunggu persetujuan atasan karena ".implode(' dan ', $reasons).'.';
    }
}
