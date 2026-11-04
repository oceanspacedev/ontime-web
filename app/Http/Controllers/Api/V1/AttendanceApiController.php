<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckInRequest;
use App\Http\Requests\Api\V1\CheckOutRequest;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Services\ApprovalFlowService;
use App\Services\FaceRecognitionService;
use App\Services\FileNamingService;
use App\Services\GeofenceService;
use App\Services\WorkScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttendanceApiController extends Controller
{
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profile absensi tidak ditemukan.'], 422);
        }

        $todayAttendance = Attendance::byWorker($user)->whereDate('date', today())->first();

        if ($todayAttendance && $todayAttendance->check_in) {
            return response()->json(['message' => 'Sudah melakukan check in hari ini.'], 409);
        }

        $company = $profile->company;
        $policy = $company?->policy;

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');

        $isOutOfBounds = false;
        if ($policy?->require_gps) {
            if (! $lat || ! $lng) {
                return response()->json(['message' => 'Lokasi GPS wajib diisi.'], 422);
            }

            if ($company) {
                $geofenceResult = GeofenceService::validateCompanyGeofence($company, (float) $lat, (float) $lng);

                if (! $geofenceResult['is_valid']) {
                    $isOutOfBounds = true;
                }
            }
        }

        if ($policy?->require_photo && ! $request->hasFile('photo')) {
            return response()->json(['message' => 'Foto selfie absensi wajib diunggah.'], 422);
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            if (! $file->isValid()) {
                return response()->json(['message' => 'File foto selfie yang diunggah tidak valid atau gagal disimpan di temporary server.'], 422);
            }
            $identifier = $profile->employee_code ?? $profile->nik ?? (string) ($profile->id ?? $user->id);
            $photoPath = FileNamingService::storeUploadedFile(
                $file,
                'attendance/photos',
                'ATT_IN',
                $identifier,
                config('filesystems.default')
            );
        }

        $isFaceVerified = null;
        $faceMatchScore = null;
        $faceVerificationNotes = null;
        $faceFailedAndRequiresApproval = false;

        if ($policy?->require_face_recognition) {
            $faceResult = app(FaceRecognitionService::class)->verifyFace(
                $photoPath,
                $profile->master_face_photo ?? null,
                $policy
            );

            $isFaceVerified = $faceResult['is_matched'];
            $faceMatchScore = $faceResult['score'];
            $faceVerificationNotes = $faceResult['notes'];

            if (! $isFaceVerified) {
                if ($policy->face_fail_action === 'reject') {
                    return response()->json([
                        'message' => 'Verifikasi Face Recognition Gagal: '.$faceVerificationNotes,
                    ], 422);
                } else {
                    $faceFailedAndRequiresApproval = true;
                }
            }
        }

        // Late calculation from today's shift (schedule, default shift, then company policy)
        $now = now();
        $workSchedule = app(WorkScheduleService::class);
        $workingHours = $workSchedule->resolve($profile, $now);

        $requiresApproval = $isOutOfBounds || $faceFailedAndRequiresApproval;
        $lateMinutes = $requiresApproval ? 0 : $workSchedule->lateMinutes($workingHours, $now);
        $status = match (true) {
            $requiresApproval => AttendanceStatus::PendingApproval,
            $lateMinutes > 0 => AttendanceStatus::Late,
            default => AttendanceStatus::OnTime,
        };

        $attendance = Attendance::updateOrCreate(
            [
                'employee_id' => $employee?->id,
                'intern_id' => $intern?->id,
                'freelancer_id' => $freelancer?->id,
                'date' => today()->toDateString(),
            ],
            [
                'shift_id' => $workingHours['shift']?->id,
                'check_in' => $now->toTimeString(),
                'check_in_lat' => $lat,
                'check_in_lng' => $lng,
                'check_in_photo' => $photoPath,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'is_out_of_bounds' => $isOutOfBounds,
                'is_face_verified' => $isFaceVerified,
                'face_match_score' => $faceMatchScore,
                'face_verification_notes' => $faceVerificationNotes,
            ]
        );

        if ($requiresApproval) {
            app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');
        }

        return response()->json([
            'message' => $requiresApproval
                ? 'Check in berhasil dicatat dan sedang menunggu persetujuan atasan.'
                : 'Check in berhasil',
            'attendance' => new AttendanceResource($attendance),
        ]);
    }

    public function checkOut(CheckOutRequest $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profile absensi tidak ditemukan.'], 422);
        }

        $attendance = Attendance::byWorker($user)->whereDate('date', today())->first();

        if (! $attendance || ! $attendance->check_in) {
            return response()->json(['message' => 'Anda belum melakukan check in hari ini.'], 422);
        }

        if ($attendance->check_out) {
            return response()->json(['message' => 'Sudah melakukan check out hari ini.'], 409);
        }

        $company = $profile->company;
        $policy = $company?->policy;

        $lat = $request->input('latitude');
        $lng = $request->input('longitude');

        $isOutOfBounds = false;
        if ($policy?->require_gps) {
            if (! $lat || ! $lng) {
                return response()->json(['message' => 'Lokasi GPS wajib diisi.'], 422);
            }

            if ($company) {
                $geofenceResult = GeofenceService::validateCompanyGeofence($company, (float) $lat, (float) $lng);

                if (! $geofenceResult['is_valid']) {
                    $isOutOfBounds = true;
                }
            }
        }

        if ($policy?->require_photo && ! $request->hasFile('photo')) {
            return response()->json(['message' => 'Foto selfie absensi wajib diunggah.'], 422);
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            if (! $file->isValid()) {
                return response()->json(['message' => 'File foto selfie yang diunggah tidak valid atau gagal disimpan di temporary server.'], 422);
            }
            $identifier = $profile->employee_code ?? $profile->nik ?? (string) ($profile->id ?? $user->id);
            $photoPath = FileNamingService::storeUploadedFile(
                $file,
                'attendance/photos',
                'ATT_OUT',
                $identifier,
                config('filesystems.default')
            );
        }

        $updateData = [
            'check_out' => now()->toTimeString(),
            'check_out_lat' => $lat,
            'check_out_lng' => $lng,
            'check_out_photo' => $photoPath,
        ];

        if ($isOutOfBounds) {
            $updateData['is_out_of_bounds'] = true;
            $updateData['status'] = AttendanceStatus::PendingApproval;
        }

        $attendance->update($updateData);

        if ($isOutOfBounds) {
            if ($attendance->approvalSteps()->count() === 0) {
                app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');
            }
        }

        return response()->json([
            'message' => $isOutOfBounds
                ? 'Check out berhasil dicatat dan sedang menunggu persetujuan atasan karena di luar geofence.'
                : 'Check out berhasil',
            'attendance' => new AttendanceResource($attendance),
        ]);
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

        $disk = config('filesystems.default');
        $photoUrl = $disk === 's3'
            ? Storage::disk('s3')->temporaryUrl($photoPath, now()->addDays(7))
            : Storage::disk($disk)->url($photoPath);

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

        $attendance = Attendance::byWorker($user)->whereDate('date', today())->first();

        return response()->json([
            'date' => today()->toDateString(),
            'has_checked_in' => $attendance?->check_in !== null,
            'has_checked_out' => $attendance?->check_out !== null,
            'attendance' => $attendance ? new AttendanceResource($attendance) : null,
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
