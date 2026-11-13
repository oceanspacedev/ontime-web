<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckInRequest;
use App\Http\Requests\Api\V1\CheckOutRequest;
use App\Http\Requests\Api\V1\LivenessChallengeRequest;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\AttendanceCheckService;
use App\Services\FileNamingService;
use App\Services\WorkScheduleService;
use App\Support\StorageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceApiController extends Controller
{
    public function livenessChallenge(LivenessChallengeRequest $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
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
        if ($request->hasFile('photo')) {
            $photoPath = FileNamingService::storeUploadedFile(
                $request->file('photo'),
                'attendance/photos',
                $isCheckIn ? 'ATT_IN' : 'ATT_OUT',
                $identifier,
                $disk
            );
        }

        $framePaths = [];
        foreach ($request->file('liveness_frames', []) as $frame) {
            $framePath = FileNamingService::storeUploadedFile(
                $frame,
                'attendance/liveness',
                $isCheckIn ? 'LIVE_IN' : 'LIVE_OUT',
                $identifier,
                $disk
            );
            $framePaths[] = $framePath;
        }

        $input = new AttendanceCheckInput(
            latitude: $request->filled('latitude') ? (float) $request->input('latitude') : null,
            longitude: $request->filled('longitude') ? (float) $request->input('longitude') : null,
            accuracy: $request->filled('accuracy') ? (float) $request->input('accuracy') : null,
            isMocked: $request->boolean('is_mocked'),
            photoPath: $photoPath,
            livenessChallengeId: $request->input('liveness_challenge_id'),
            livenessFramePaths: $framePaths,
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

        $attendances = $query->orderBy('date', 'desc')->paginate(20);

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
        $attendance = Attendance::with(['employee', 'intern', 'freelancer', 'approvalSteps'])->findOrFail($id);

        if (! Attendance::query()->visibleTo($user)->whereKey($attendance->getKey())->exists()) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        return response()->json([
            'data' => new AttendanceResource($attendance),
        ]);
    }

    public function registerMasterFace(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ], [
            'photo.required' => 'Foto Master Wajah wajib diunggah.',
            'photo.image' => 'Foto Master Wajah harus berupa berkas gambar.',
            'photo.mimes' => 'Foto Master Wajah harus berformat jpeg, png, jpg, atau webp.',
            'photo.max' => 'Foto Master Wajah maksimal berukuran 5MB.',
        ]);

        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $file = $request->file('photo');
        if (! $file || ! $file->isValid()) {
            return response()->json(['message' => 'File foto master wajah tidak valid atau gagal disimpan di temporary server.'], 422);
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
            'message' => 'Foto Master Wajah berhasil diperbarui',
            'data' => [
                'master_face_photo' => $photoUrl,
                'master_face_verified_at' => $profile->master_face_verified_at->toISOString(),
            ],
        ]);
    }

    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $workSchedule = app(WorkScheduleService::class);
        // On a split shift, "today" is the segment the worker is on: segment 2 starts fresh once segment 1 is checked out.
        ['segment' => $segment, 'attendance' => $attendance] = $workSchedule->currentSegment($user, $profile, today());
        $hours = $workSchedule->resolve($profile, today(), $segment);
        $segments = $hours['shift']?->segments() ?? [];
        $doneToday = Attendance::byWorker($user)->with(['employee', 'intern', 'freelancer'])->whereDate('date', today())->orderBy('segment')->get();

        return response()->json([
            'date' => today()->toDateString(),
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
                'outlet' => $hours['location'] ? ['id' => (int) $hours['location']->id, 'name' => $hours['location']->name] : null,
                'section' => $hours['section'],
            ],
        ]);
    }

    public function deleteMasterFace(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        if (! $profile->master_face_photo) {
            return response()->json(['message' => 'Foto Master Wajah belum pernah didaftarkan.'], 400);
        }

        $profile->update([
            'master_face_photo' => null,
            'master_face_verified_at' => null,
        ]);

        return response()->json([
            'message' => 'Foto Master Wajah berhasil dihapus.',
        ]);
    }
}
