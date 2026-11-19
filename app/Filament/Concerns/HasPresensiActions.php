<?php

namespace App\Filament\Concerns;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceCheckResult;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\Attendance\WorkSession;
use App\Services\AttendanceCheckService;
use App\Services\OffScheduleCheckInService;
use App\Services\SessionResolver;
use App\Services\WorkScheduleService;
use App\Support\SelfieUploadToken;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Livewire\Attributes\Renderless;

/**
 * Web check-in and check-out ("Presensi Mandiri") for the page and the dashboard widget.
 *
 * The rules live in {@see AttendanceCheckService}, shared with the mobile API. This trait only turns
 * upload tokens into storage paths, calls the service and reports the outcome to the camera component
 * (as a return value, shown inline) and as a Filament notification.
 */
trait HasPresensiActions
{
    /** "Masuk di luar jadwal": the id of the company shift the worker covers (required for such a check-in). */
    public ?string $offScheduleShiftId = null;

    /** "Masuk di luar jadwal": the colleague replaced, as "employee:12", or null. */
    public ?string $offScheduleReplaces = null;

    /** "Masuk di luar jadwal": the id of the outlet the worker covers at, or null (matched by the geofence). */
    public ?string $offScheduleLocationId = null;

    /**
     * The attendance of the session the worker is on now ({@see SessionResolver::current()}): the open row that
     * can still be checked out (a shift of yesterday after midnight included), else the next session's row.
     */
    public function getTodayAttendanceProperty(): ?Attendance
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $profile = $this->getLinkedProfile();

        if (! $profile || ! $user instanceof User) {
            return Attendance::byWorker($user)->today()->first();
        }

        return $this->currentSession($user, $profile)['attendance'];
    }

    /**
     * @return array{session: WorkSession|null, attendance: Attendance|null, date: CarbonImmutable, sessions: list<array{session: WorkSession, attendance: Attendance|null}>}
     */
    private function currentSession(User $user, Employee|Intern|Freelancer $profile): array
    {
        return app(SessionResolver::class)->current($profile, now(), app(AttendanceCheckService::class)->closableAttendance($user));
    }

    public function getLinkedProfile(): mixed
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->intern ?? $user?->freelancer;
    }

    /**
     * Working hours of the session the worker is on now; without a session (a day off, or no schedule) the
     * day's own hours (the company hours).
     *
     * @return array{shift: Shift|null, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string, location: CompanyLocation|null, section: string|null, segment: int, segment_count: int}|null
     */
    public function getTodayWorkingHoursProperty(): ?array
    {
        $profile = $this->getLinkedProfile();

        $user = auth()->user();

        if (! $profile || ! $user instanceof User) {
            return null;
        }

        return $this->currentSession($user, $profile)['session']?->toWorkingHours()
            ?? app(WorkScheduleService::class)->resolve($profile, today());
    }

    /**
     * What a check-in today outside the schedule offers (shifts to cover, colleagues to replace), or null
     * when the worker has no profile. See {@see OffScheduleCheckInService::options()}.
     *
     * @return array{date: string, is_off_schedule: bool, off_schedule_reason: string|null, shifts: list<array<string, mixed>>, colleagues: list<array<string, mixed>>}|null
     */
    public function getCoverOptionsProperty(): ?array
    {
        $profile = $this->getLinkedProfile();
        $user = auth()->user();

        if (! $profile || ! $user instanceof User) {
            return null;
        }

        // The date the cover is booked to: yesterday for a night shift covered after midnight, as the API does.
        $date = app(AttendanceCheckService::class)->coverBusinessDate($user, ctype_digit((string) $this->offScheduleShiftId) ? (int) $this->offScheduleShiftId : null);

        return app(OffScheduleCheckInService::class)->options($profile, $date);
    }

    public function getCompanyPolicyProperty(): ?CompanyPolicy
    {
        $profile = $this->getLinkedProfile();

        return $profile?->company?->policy;
    }

    /**
     * Check in with upload tokens from the camera component (never raw storage paths).
     *
     * @param  list<string>  $livenessFrameTokens  Upload tokens of the challenge frames, in step order.
     * @return array{ok: bool, status: int, field: string|null, message: string, requires_approval: bool}
     */
    public function processCheckIn(
        ?string $photoToken = null,
        ?float $lat = null,
        ?float $lng = null,
        ?string $livenessChallengeId = null,
        array $livenessFrameTokens = [],
    ): array {
        return $this->runAttendanceCheck(AttendanceCheckService::PURPOSE_CHECK_IN, $photoToken, $lat, $lng, $livenessChallengeId, $livenessFrameTokens);
    }

    /**
     * Check out with upload tokens from the camera component (never raw storage paths).
     *
     * @param  list<string>  $livenessFrameTokens  Upload tokens of the challenge frames, in step order.
     * @return array{ok: bool, status: int, field: string|null, message: string, requires_approval: bool}
     */
    public function processCheckOut(
        ?string $photoToken = null,
        ?float $lat = null,
        ?float $lng = null,
        ?string $livenessChallengeId = null,
        array $livenessFrameTokens = [],
    ): array {
        return $this->runAttendanceCheck(AttendanceCheckService::PURPOSE_CHECK_OUT, $photoToken, $lat, $lng, $livenessChallengeId, $livenessFrameTokens);
    }

    /**
     * Start a liveness challenge for the camera component.
     *
     * On success the {@see AttendanceCheckService::livenessChallenge()} payload is returned with `ok` true;
     * otherwise `ok` is false and `message` says why (shown inline by the camera component).
     *
     * @return array<string, mixed>
     */
    #[Renderless]
    public function issueLivenessChallenge(string $purpose): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return ['ok' => false, 'message' => 'Sesi Anda telah berakhir. Silakan login kembali.'];
        }

        try {
            return ['ok' => true, ...app(AttendanceCheckService::class)->livenessChallenge($user, $purpose)];
        } catch (AttendanceRuleException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @param  array<int, mixed>  $livenessFrameTokens
     * @return array{ok: bool, status: int, field: string|null, message: string, requires_approval: bool}
     */
    private function runAttendanceCheck(
        string $purpose,
        ?string $photoToken,
        ?float $lat,
        ?float $lng,
        ?string $livenessChallengeId,
        array $livenessFrameTokens,
    ): array {
        $isCheckIn = $purpose === AttendanceCheckService::PURPOSE_CHECK_IN;
        $actionLabel = $isCheckIn ? 'Check In' : 'Check Out';
        $user = auth()->user();
        $service = app(AttendanceCheckService::class);
        $resolvedPaths = [];

        try {
            if (! $user instanceof User) {
                throw new AttendanceRuleException('Sesi Anda telah berakhir. Silakan login kembali.', 401);
            }

            $this->ensureValidCoordinates($lat, $lng);

            try {
                $photoPath = filled($photoToken) ? SelfieUploadToken::resolve($photoToken, $user) : null;
                $resolvedPaths = array_filter([$photoPath]);
                $framePaths = $this->resolveLivenessFrames($livenessFrameTokens, $user, $resolvedPaths);
            } catch (AttendanceRuleException $exception) {
                // The service never saw these uploads, so remove the ones resolved so far here.
                $service->discardUploads($user, $resolvedPaths);

                throw $exception;
            }

            $input = new AttendanceCheckInput(
                latitude: $lat,
                longitude: $lng,
                photoPath: $photoPath,
                livenessChallengeId: filled($livenessChallengeId) ? $livenessChallengeId : null,
                livenessFramePaths: $framePaths,
                coverShiftId: $isCheckIn && ctype_digit((string) $this->offScheduleShiftId) ? (int) $this->offScheduleShiftId : null,
                replacesWorker: $isCheckIn && filled($this->offScheduleReplaces) ? $this->offScheduleReplaces : null,
                coverLocationId: $isCheckIn && ctype_digit((string) $this->offScheduleLocationId) ? (int) $this->offScheduleLocationId : null,
            );

            $result = $isCheckIn ? $service->checkIn($user, $input) : $service->checkOut($user, $input);
        } catch (AttendanceRuleException $exception) {
            $notification = Notification::make()
                ->title($this->attendanceErrorTitle($actionLabel, $exception))
                ->body($exception->getMessage());

            ($exception->status === 409 ? $notification->warning() : $notification->danger())->send();

            return [
                'ok' => false,
                'status' => $exception->status,
                'field' => $exception->field,
                'message' => $exception->getMessage(),
                'requires_approval' => false,
            ];
        }

        $this->notifyAttendanceResult($actionLabel, $result, $isCheckIn);

        return [
            'ok' => true,
            'status' => 200,
            'field' => null,
            'message' => $result->message,
            'requires_approval' => $result->requiresApproval,
        ];
    }

    /**
     * Resolve the upload tokens of the liveness frames, keeping the step order.
     *
     * @param  array<int, mixed>  $tokens
     * @param  array<int, string>  $resolvedPaths  Receives every path resolved so far, for cleanup.
     * @return list<string>
     *
     * @throws AttendanceRuleException
     */
    private function resolveLivenessFrames(array $tokens, User $user, array &$resolvedPaths): array
    {
        $paths = [];

        foreach (array_values($tokens) as $token) {
            try {
                $paths[] = $resolvedPaths[] = SelfieUploadToken::resolve(is_string($token) ? $token : '', $user);
            } catch (AttendanceRuleException $exception) {
                throw new AttendanceRuleException($exception->getMessage(), $exception->status, 'liveness_frames');
            }
        }

        return $paths;
    }

    /**
     * Refuse coordinates outside the GPS domain, with the same rule and message as the API
     * (CheckInRequest: latitude -90..90, longitude -180..180).
     *
     * @throws AttendanceRuleException
     */
    private function ensureValidCoordinates(?float $lat, ?float $lng): void
    {
        foreach (['latitude' => [$lat, 90.0], 'longitude' => [$lng, 180.0]] as $field => [$value, $limit]) {
            if ($value !== null && (! is_finite($value) || abs($value) > $limit)) {
                throw new AttendanceRuleException('Lokasi GPS tidak valid. Perbarui lokasi lalu coba lagi.', 422, $field);
            }
        }
    }

    private function attendanceErrorTitle(string $actionLabel, AttendanceRuleException $exception): string
    {
        return match (true) {
            $exception->getMessage() === AttendanceCheckService::ON_LEAVE_MESSAGE => 'Sedang Cuti / Izin',
            $exception->getMessage() === AttendanceCheckService::OPEN_OVERNIGHT_MESSAGE => 'Shift Semalam Belum Selesai',
            $exception->status === 409 => "Sudah {$actionLabel}",
            $exception->status === 503 => 'Layanan Wajah Tidak Tersedia',
            $exception->field === 'liveness_frames' => 'Verifikasi Liveness Gagal',
            default => "{$actionLabel} Gagal",
        };
    }

    private function notifyAttendanceResult(string $actionLabel, AttendanceCheckResult $result, bool $isCheckIn): void
    {
        if ($result->requiresApproval) {
            Notification::make()
                ->title("{$actionLabel} Dikirim (Menunggu Persetujuan)")
                ->body($result->message)
                ->warning()
                ->send();

            return;
        }

        $attendance = $result->attendance;
        $time = ($isCheckIn ? $attendance->check_in : $attendance->check_out)?->format('H:i:s') ?? now()->format('H:i:s');
        $body = "Waktu {$actionLabel}: {$time} WIB";

        if ($isCheckIn) {
            $body .= $attendance->status === AttendanceStatus::Late
                ? " (Terlambat {$attendance->late_minutes} menit)"
                : ' (Tepat Waktu)';
        }

        Notification::make()
            ->title("{$actionLabel} Berhasil!")
            ->body($body)
            ->success()
            ->send();
    }
}
