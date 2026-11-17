<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckInRequest;
use App\Http\Requests\Api\V1\CheckOutRequest;
use App\Http\Requests\Api\V1\LivenessChallengeRequest;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\CompanyLocation;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\AttendanceCheckService;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;
use App\Services\FileNamingService;
use App\Services\GeofenceService;
use App\Services\OffScheduleCheckInService;
use App\Services\UploadStorageException;
use App\Services\WorkScheduleService;
use App\Support\StorageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class AttendanceApiController extends Controller
{
    /** How far back the Today card looks for a shift whose check-out was forgotten. */
    private const UNCLOSED_LOOKBACK_DAYS = 7;

    public function livenessChallenge(LivenessChallengeRequest $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        // Refused before the worker spends GPS, poses and uploads on a check-in the leave would refuse anyway.
        if ($request->validated('purpose') === AttendanceCheckService::PURPOSE_CHECK_IN && $this->isOnApprovedLeaveToday($request->user())) {
            return $this->ruleError(new AttendanceRuleException(AttendanceCheckService::ON_LEAVE_MESSAGE, 409));
        }

        try {
            return response()->json($attendanceCheck->livenessChallenge($request->user(), $request->validated('purpose')));
        } catch (AttendanceRuleException $exception) {
            return $this->ruleError($exception);
        }
    }

    public function checkIn(CheckInRequest $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        return $this->runCheck($request, AttendanceCheckService::PURPOSE_CHECK_IN, $attendanceCheck);
    }

    public function checkOut(CheckOutRequest $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        return $this->runCheck($request, AttendanceCheckService::PURPOSE_CHECK_OUT, $attendanceCheck);
    }

    /**
     * Store the uploaded selfie and liveness frames, then let the shared service apply the policy.
     *
     * The service owns the stored files from here: it removes those of a refused attempt and those
     * the saved record does not use.
     */
    private function runCheck(CheckInRequest|CheckOutRequest $request, string $purpose, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        $user = $request->user();
        $isCheckIn = $purpose === AttendanceCheckService::PURPOSE_CHECK_IN;
        $disk = config('filesystems.default');
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;
        $identifier = $profile?->employee_code ?? $profile?->nik ?? (string) ($profile?->id ?? $user->id);

        $photoPath = null;
        $framePaths = [];

        try {
            if ($request->hasFile('photo')) {
                $photoPath = FileNamingService::storeUploadedFile(
                    $request->file('photo'),
                    'attendance/photos',
                    $isCheckIn ? 'ATT_IN' : 'ATT_OUT',
                    $identifier,
                    $disk
                );
            }

            foreach ($request->file('liveness_frames', []) as $frame) {
                $framePaths[] = FileNamingService::storeUploadedFile(
                    $frame,
                    'attendance/liveness',
                    $isCheckIn ? 'LIVE_IN' : 'LIVE_OUT',
                    $identifier,
                    $disk
                );
            }
        } catch (UploadStorageException) {
            // Files written before the failure belong to no record; remove them before asking for a resend.
            $stored = array_values(array_filter([$photoPath, ...$framePaths]));
            if ($stored !== []) {
                Storage::disk($disk)->delete($stored);
            }

            return $this->ruleError(new AttendanceRuleException('Foto gagal disimpan di server. Coba kirim lagi beberapa saat.', 503, 'photo'));
        }

        $input = new AttendanceCheckInput(
            latitude: $request->filled('latitude') ? (float) $request->input('latitude') : null,
            longitude: $request->filled('longitude') ? (float) $request->input('longitude') : null,
            accuracy: $request->filled('accuracy') ? (float) $request->input('accuracy') : null,
            isMocked: $request->boolean('is_mocked'),
            photoPath: $photoPath,
            livenessChallengeId: $request->input('liveness_challenge_id'),
            livenessFramePaths: $framePaths,
            coverShiftId: $isCheckIn && $request->filled('cover_shift_id') ? (int) $request->input('cover_shift_id') : null,
            replacesWorker: $isCheckIn && $request->filled('replaces_worker') ? (string) $request->input('replaces_worker') : null,
        );

        try {
            $result = $isCheckIn
                ? $attendanceCheck->checkIn($user, $input)
                : $attendanceCheck->checkOut($user, $input);
        } catch (AttendanceRuleException $exception) {
            return $this->ruleError($exception);
        }

        return response()->json([
            'message' => $result->message,
            'attendance' => new AttendanceResource($result->attendance),
            'requires_approval' => $result->requiresApproval,
            'approval_reasons' => $result->approvalReasons,
        ]);
    }

    /**
     * What the app offers for "Masuk di luar jadwal": whether a check-in now is outside the worker's
     * schedule, the active shifts of the company to cover, and the colleagues rostered that date.
     *
     * The date is today, or yesterday when the optional cover_shift_id is a night shift covered after
     * midnight: the check-in is then booked to yesterday, so the colleague it replaces is one rostered yesterday.
     */
    public function coverOptions(Request $request, OffScheduleCheckInService $offSchedule, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        $validated = $request->validate(
            ['cover_shift_id' => ['nullable', 'integer', 'min:1']],
            ['cover_shift_id.integer' => 'Shift yang dipilih tidak valid.', 'cover_shift_id.min' => 'Shift yang dipilih tidak valid.'],
        );
        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $coverShiftId = isset($validated['cover_shift_id']) ? (int) $validated['cover_shift_id'] : null;

        return response()->json($offSchedule->options($profile, $attendanceCheck->coverBusinessDate($user, $coverShiftId)));
    }

    private function ruleError(AttendanceRuleException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => [$exception->field => [$exception->getMessage()]],
        ], $exception->status);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Own records plus workers inside the user's data access, same as the panel.
        $query = Attendance::query()->with(Attendance::workerEagerLoads())->visibleTo($user);

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('date', [$request->input('start_date'), $request->input('end_date')]);
        } elseif ($request->has('month') && $request->has('year')) {
            $query->whereMonth('date', $request->input('month'))
                ->whereYear('date', $request->input('year'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Many rows share a date (a team, split shifts): the id keeps the pages disjoint.
        $attendances = $query->orderByDesc('date')->orderByDesc('segment')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => AttendanceResource::collection($attendances->items()),
            'pagination' => [
                'current_page' => $attendances->currentPage(),
                'last_page' => $attendances->lastPage(),
                'per_page' => $attendances->perPage(),
                'total' => $attendances->total(),
            ],
        ]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $attendance = Attendance::with(['employee', 'intern', 'freelancer', 'approvalSteps'])->find($id);

        if (! $attendance) {
            return response()->json(['message' => 'Data presensi tidak ditemukan.'], 404);
        }

        if (! ApprovalApiController::canOpen($attendance, $user)) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke presensi ini.'], 403);
        }

        return response()->json([
            'data' => new AttendanceResource($attendance),
        ]);
    }

    /**
     * Set or replace the worker's profile photo. The same photo is the master face that face recognition
     * and liveness compare check-in selfies with, so the worker sets both from one place.
     */
    public function registerMasterFace(Request $request, FaceVerifier $faceVerifier): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ], [
            'photo.required' => 'Foto profil wajib diunggah.',
            'photo.uploaded' => 'Foto profil gagal terunggah. Periksa koneksi lalu coba lagi.',
            'photo.file' => 'Foto profil harus berupa berkas gambar.',
            'photo.image' => 'Foto profil harus berupa berkas gambar.',
            'photo.mimes' => 'Foto profil harus berformat jpeg, png, jpg, atau webp.',
            'photo.max' => 'Foto profil maksimal berukuran 5MB.',
        ]);

        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $file = $request->file('photo');
        if (! $file || ! $file->isValid()) {
            return response()->json(['message' => 'File foto profil tidak valid atau gagal disimpan di temporary server.'], 422);
        }

        $faceProblem = $this->profilePhotoFaceProblem($faceVerifier, $file->getRealPath());
        if ($faceProblem !== null) {
            return response()->json(['message' => $faceProblem, 'errors' => ['photo' => [$faceProblem]]], 422);
        }

        $identifier = $profile->employee_code ?? $profile->nik ?? (string) ($profile->id ?? $user->id);

        $photoPath = FileNamingService::storeUploadedFile(
            $file,
            'master-faces',
            'MASTER_FACE',
            $identifier,
            config('filesystems.default')
        );

        $profile->update([
            'master_face_photo' => $photoPath,
            'master_face_verified_at' => now(),
        ]);

        $photoUrl = StorageUrl::for($photoPath);

        return response()->json([
            'message' => 'Foto profil berhasil diperbarui.',
            'data' => [
                'master_face_photo' => $photoUrl,
                'master_face_verified_at' => $profile->master_face_verified_at->toISOString(),
            ],
        ]);
    }

    /**
     * Why a profile photo cannot serve as the master face, or null when it can. Only a driver that does
     * real face recognition can tell; the built-in pixel comparison cannot, and an unreachable face
     * service does not stop the worker from setting a photo.
     */
    private function profilePhotoFaceProblem(FaceVerifier $faceVerifier, string|false $path): ?string
    {
        if ($path === false || ! $faceVerifier->isAccurate()) {
            return null;
        }

        try {
            $faces = $faceVerifier->detect($path)->faces;
        } catch (FaceServiceUnavailable) {
            return null;
        }

        return match (true) {
            $faces === 0 => 'Wajah tidak terlihat di foto. Foto profil juga dipakai untuk verifikasi wajah saat absen, jadi gunakan foto wajah Anda sendiri yang jelas.',
            $faces > 1 => 'Ada lebih dari satu wajah di foto. Gunakan foto yang hanya berisi wajah Anda.',
            default => null,
        };
    }

    public function today(Request $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $workSchedule = app(WorkScheduleService::class);
        // On a split shift, "today" is the segment the worker is on: segment 2 starts fresh once segment 1 is checked out.
        ['segment' => $segment, 'attendance' => $attendance] = $workSchedule->currentSegment($user, $profile, today());

        // After midnight an overnight shift is still open on yesterday's row: the app offers its check-out.
        if (! $attendance?->check_in) {
            $attendance = $attendanceCheck->openOvernightAttendance($user)?->loadMissing(['employee', 'intern', 'freelancer']) ?? $attendance;
        }
        $hours = $workSchedule->resolve($profile, today(), $segment);
        $segments = $hours['shift']?->segments() ?? [];
        $doneToday = Attendance::byWorker($user)->with(['employee', 'intern', 'freelancer'])->whereDate('date', today())->orderBy('segment')->get();

        return response()->json([
            'date' => today()->toDateString(),
            // Today is covered by an approved leave: check-in would be refused, so the app does not offer it.
            'is_on_leave' => $this->isApprovedLeaveRow($doneToday->firstWhere('segment', 1)),
            'has_checked_in' => $attendance?->check_in !== null,
            'has_checked_out' => $attendance?->check_out !== null,
            'attendance' => $attendance ? new AttendanceResource($attendance) : null,
            // Every segment's attendance of today (one row, or two on a split shift).
            'attendances' => AttendanceResource::collection($doneToday),
            // Today's working hours: the dated schedule, else the default shift, else the company hours.
            'schedule' => [
                'shift' => $hours['shift'] ? ['id' => (int) $hours['shift']->id, 'name' => $hours['shift']->name, 'code' => $hours['shift']->code, 'talenta_code' => $hours['shift']->talenta_code] : null,
                'is_day_off' => $hours['is_day_off'],
                'segment' => $hours['segment'],
                'segment_count' => $hours['segment_count'],
                // Every segment of the day, for showing "07:45–13:00 & 17:45–22:00".
                'segments' => $segments,
                'start_time' => substr($hours['start_time'], 0, 5),
                'end_time' => substr($hours['end_time'], 0, 5),
                'late_tolerance_minutes' => $hours['late_tolerance_minutes'],
                'outlet' => $this->outletGeofence($hours['location']),
                'section' => $hours['section'],
                // Not expected to work today (day off, or a freelancer without a roster): a check-in needs approval and may cover a colleague.
                'is_off_schedule' => $workSchedule->isOffSchedule($profile, $hours),
            ],
            // Where check-out is accepted: the outlet the open attendance was checked in at.
            'check_out_outlet' => $attendance?->check_in !== null && $attendance->check_out === null
                ? $this->outletGeofence($attendance->loadMissing('location')->location)
                : null,
            // An earlier shift was never checked out: the app points to a correction, today's check-in stays open.
            'unclosed_previous' => $this->unclosedPrevious($user, $attendance),
        ]);
    }

    /**
     * The latest row of the last UNCLOSED_LOOKBACK_DAYS days (before today) that was checked in but never
     * checked out and is not the one offered for check-out now (an overnight shift still running is checked
     * out as usual). Every segment counts, so a forgotten segment 1 of a split shift is found too, and a
     * forgotten shift before a day off still shows after it. A row whose date already has a pending
     * correction gives way to an older one that has none. The worker asks for a correction with the real
     * check-out time; checking out now would record the wrong time.
     *
     * @return array{date: string, check_in: string|null, shift: string|null, correction_pending: bool}|null
     */
    private function unclosedPrevious(User $user, ?Attendance $shown): ?array
    {
        $unclosed = Attendance::byWorker($user)
            ->with('shift')
            ->whereDate('date', '<', today())
            ->whereDate('date', '>=', today()->subDays(self::UNCLOSED_LOOKBACK_DAYS))
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->orderByDesc('date')
            ->orderByDesc('segment')
            ->get()
            ->reject(fn (Attendance $row): bool => $shown !== null && $shown->is($row))
            ->values();

        if ($unclosed->isEmpty()) {
            return null;
        }

        $pendingDates = AttendanceCorrection::query()
            ->where(array_filter(Attendance::workerKeys($user)))
            ->whereDate('date', '>=', $unclosed->last()->date)
            ->whereDate('date', '<=', $unclosed->first()->date)
            ->where('status', 'pending')
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->flip();

        /** @var Attendance $row */
        $row = $unclosed->first(fn (Attendance $row): bool => ! $pendingDates->has($row->date->toDateString())) ?? $unclosed->first();

        return [
            'date' => $row->date->toDateString(),
            'check_in' => $row->check_in?->format('H:i'),
            'shift' => $row->shift?->name,
            'correction_pending' => $pendingDates->has($row->date->toDateString()),
        ];
    }

    /**
     * Whether today's first-segment row of the worker is an approved leave.
     */
    private function isOnApprovedLeaveToday(User $user): bool
    {
        return $this->isApprovedLeaveRow(Attendance::byWorker($user)->whereDate('date', today())->where('segment', 1)->first());
    }

    /**
     * Whether the row is a leave day backed by an approved leave request, the same rule check-in applies.
     *
     * A leave row whose leave was deleted or re-dated no longer blocks check-in.
     */
    private function isApprovedLeaveRow(?Attendance $attendance): bool
    {
        if ($attendance === null || $attendance->check_in !== null || $attendance->status !== AttendanceStatus::Leave) {
            return false;
        }

        $workerKeys = array_filter([
            'employee_id' => $attendance->employee_id,
            'intern_id' => $attendance->intern_id,
            'freelancer_id' => $attendance->freelancer_id,
        ]);

        if ($workerKeys === []) {
            return false;
        }

        $date = $attendance->date->toDateString();

        return LeaveRequest::query()
            ->where($workerKeys)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    /**
     * An outlet with the geofence the server checks against, so the app's preview matches it.
     *
     * @return array{id: int, name: string, latitude: float|null, longitude: float|null, radius_meters: int}|null
     */
    private function outletGeofence(?CompanyLocation $outlet): ?array
    {
        if (! $outlet) {
            return null;
        }

        $outlet->loadMissing('company.policy');

        return [
            'id' => (int) $outlet->id,
            'name' => $outlet->name,
            'latitude' => $outlet->latitude !== null ? (float) $outlet->latitude : null,
            'longitude' => $outlet->longitude !== null ? (float) $outlet->longitude : null,
            'radius_meters' => GeofenceService::resolveRadius($outlet->radius_meters, $outlet->company?->policy?->geofence_radius_meters),
        ];
    }

    public function deleteMasterFace(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        if (! $profile->master_face_photo) {
            return response()->json(['message' => 'Foto profil belum pernah dipasang.'], 400);
        }

        $profile->update([
            'master_face_photo' => null,
            'master_face_verified_at' => null,
        ]);

        return response()->json([
            'message' => 'Foto profil berhasil dihapus.',
        ]);
    }
}
