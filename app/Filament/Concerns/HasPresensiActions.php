<?php

namespace App\Filament\Concerns;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\CompanyPolicy;
use App\Models\Shift;
use App\Services\ApprovalFlowService;
use App\Services\FaceRecognitionService;
use App\Services\GeofenceService;
use App\Services\WorkScheduleService;
use Filament\Notifications\Notification;

trait HasPresensiActions
{
    public function getTodayAttendanceProperty(): ?Attendance
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        return Attendance::byWorker($user)->today()->first();
    }

    public function getLinkedProfile(): mixed
    {
        $user = auth()->user();

        return $user?->employee ?? $user?->intern ?? $user?->freelancer;
    }

    /**
     * Working hours that apply today (dated schedule, default shift, or company policy).
     *
     * @return array{shift: Shift|null, is_day_off: bool, start_time: string, end_time: string, late_tolerance_minutes: int, source: string}|null
     */
    public function getTodayWorkingHoursProperty(): ?array
    {
        $profile = $this->getLinkedProfile();

        return $profile ? app(WorkScheduleService::class)->resolve($profile, now()) : null;
    }

    public function getCompanyPolicyProperty(): ?CompanyPolicy
    {
        $profile = $this->getLinkedProfile();

        return $profile?->company?->policy;
    }

    public function processCheckIn(?string $photoPath = null, ?float $lat = null, ?float $lng = null): void
    {
        $existingAttendance = $this->todayAttendance;

        if ($existingAttendance?->check_in) {
            Notification::make()
                ->title('Sudah Check In')
                ->body('Anda sudah melakukan check in hari ini.')
                ->warning()
                ->send();

            return;
        }

        $policy = $this->companyPolicy;
        $requirePhoto = $policy?->require_photo ?? false;
        $requireGps = $policy?->require_gps ?? false;
        $requireFaceRecognition = $policy?->require_face_recognition ?? false;
        $isPhotoNeeded = $requirePhoto || $requireFaceRecognition;

        if ($isPhotoNeeded && empty($photoPath)) {
            Notification::make()
                ->title('Foto Selfie Wajib')
                ->body('Foto selfie tidak terdeteksi. Silakan coba lagi.')
                ->danger()
                ->send();

            return;
        }

        if ($requireGps && (empty($lat) || empty($lng))) {
            Notification::make()
                ->title('Sinyal GPS Diperlukan')
                ->body('Koordinat GPS belum terdeteksi. Pastikan izin lokasi diaktifkan di browser Anda.')
                ->danger()
                ->send();

            return;
        }

        $profile = $this->getLinkedProfile();
        if (! $profile) {
            Notification::make()
                ->title('Profil Tidak Ditemukan')
                ->body('Data profil tidak ditemukan untuk akun ini.')
                ->danger()
                ->send();

            return;
        }

        $user = auth()->user();
        $employee = $user?->employee;
        $intern = $user?->intern;
        $freelancer = $user?->freelancer;
        $company = $profile->company;

        // Face Recognition validation
        $isFaceVerified = null;
        $faceMatchScore = null;
        $faceVerificationNotes = null;
        $faceFailedAndRequiresApproval = false;

        if ($policy?->require_face_recognition) {
            if (empty($profile->master_face_photo)) {
                Notification::make()
                    ->title('Foto Master Wajah Belum Terdaftar')
                    ->body('Silakan daftarkan Foto Master Wajah Anda terlebih dahulu.')
                    ->warning()
                    ->send();

                return;
            }

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
                    Notification::make()
                        ->title('Verifikasi Wajah Gagal')
                        ->body($faceVerificationNotes)
                        ->danger()
                        ->send();

                    return;
                } else {
                    $faceFailedAndRequiresApproval = true;
                }
            }
        }

        // Multi-location Geofence validation
        $isOutOfBounds = false;
        if ($policy?->require_gps && ! empty($lat) && ! empty($lng) && $company) {
            $result = GeofenceService::validateCompanyGeofence(
                $company,
                (float) $lat,
                (float) $lng
            );

            if (! $result['is_valid']) {
                $isOutOfBounds = true;
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

        // Reuse today's row (for example one created by an approved correction) instead of
        // inserting a second row, which the unique (worker, date) index rejects.
        $attendance = $existingAttendance ?? new Attendance([
            'employee_id' => $employee?->id,
            'intern_id' => $intern?->id,
            'freelancer_id' => $freelancer?->id,
            'date' => today(),
        ]);

        $attendance->fill([
            'shift_id' => $workingHours['shift']?->id,
            'check_in' => $now,
            'check_in_photo' => $photoPath,
            'check_in_lat' => $lat,
            'check_in_lng' => $lng,
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'is_out_of_bounds' => $isOutOfBounds,
            'is_face_verified' => $isFaceVerified,
            'face_match_score' => $faceMatchScore,
            'face_verification_notes' => $faceVerificationNotes,
        ])->save();

        if ($requiresApproval) {
            app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');

            $reasonNote = $faceFailedAndRequiresApproval ? 'Verifikasi wajah tidak cocok. ' : '';
            $reasonNote .= $isOutOfBounds ? 'Lokasi berada di luar geofence.' : '';

            Notification::make()
                ->title('Check In Dikirim (Menunggu Persetujuan)')
                ->body($reasonNote.' Presensi berhasil dicatat dan sedang menunggu persetujuan atasan.')
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Check In Berhasil!')
                ->body("Waktu Check In: {$now->format('H:i:s')} WIB (".($status === AttendanceStatus::Late ? "Terlambat {$lateMinutes} menit" : 'Tepat Waktu').')')
                ->success()
                ->send();
        }
    }

    public function processCheckOut(?string $photoPath = null, ?float $lat = null, ?float $lng = null): void
    {
        $attendance = $this->todayAttendance;
        if (! $attendance?->check_in) {
            Notification::make()
                ->title('Check Out Gagal')
                ->body('Anda belum melakukan Check In hari ini.')
                ->danger()
                ->send();

            return;
        }

        if ($attendance->check_out) {
            Notification::make()
                ->title('Sudah Check Out')
                ->body('Anda sudah melakukan check out hari ini.')
                ->warning()
                ->send();

            return;
        }

        $policy = $this->companyPolicy;
        $requirePhoto = $policy?->require_photo ?? false;
        $requireGps = $policy?->require_gps ?? false;
        $requireFaceRecognition = $policy?->require_face_recognition ?? false;
        $isPhotoNeeded = $requirePhoto || $requireFaceRecognition;

        if ($isPhotoNeeded && empty($photoPath)) {
            Notification::make()
                ->title('Foto Selfie Wajib')
                ->body('Foto selfie tidak terdeteksi. Silakan coba lagi.')
                ->danger()
                ->send();

            return;
        }

        if ($requireGps && (empty($lat) || empty($lng))) {
            Notification::make()
                ->title('Sinyal GPS Diperlukan')
                ->body('Koordinat GPS belum terdeteksi. Pastikan izin lokasi diaktifkan di browser Anda.')
                ->danger()
                ->send();

            return;
        }

        $profile = $this->getLinkedProfile();
        $company = $profile?->company;

        // Face Recognition validation
        $isFaceVerified = null;
        $faceMatchScore = null;
        $faceVerificationNotes = null;
        $faceFailedAndRequiresApproval = false;

        if ($policy?->require_face_recognition) {
            if (empty($profile->master_face_photo)) {
                Notification::make()
                    ->title('Foto Master Wajah Belum Terdaftar')
                    ->body('Silakan daftarkan Foto Master Wajah Anda terlebih dahulu.')
                    ->warning()
                    ->send();

                return;
            }

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
                    Notification::make()
                        ->title('Verifikasi Wajah Gagal')
                        ->body($faceVerificationNotes)
                        ->danger()
                        ->send();

                    return;
                } else {
                    $faceFailedAndRequiresApproval = true;
                }
            }
        }

        $isOutOfBounds = false;
        if ($policy?->require_gps && ! empty($lat) && ! empty($lng) && $company) {
            $result = GeofenceService::validateCompanyGeofence(
                $company,
                (float) $lat,
                (float) $lng
            );

            if (! $result['is_valid']) {
                $isOutOfBounds = true;
            }
        }

        $requiresApproval = $isOutOfBounds || $faceFailedAndRequiresApproval;
        $now = now();
        $updateData = [
            'check_out' => $now,
            'check_out_photo' => $photoPath,
            'check_out_lat' => $lat,
            'check_out_lng' => $lng,
            'is_face_verified' => $isFaceVerified ?? $attendance->is_face_verified,
            'face_match_score' => $faceMatchScore ?? $attendance->face_match_score,
            'face_verification_notes' => $faceVerificationNotes ?? $attendance->face_verification_notes,
        ];

        if ($requiresApproval) {
            $updateData['is_out_of_bounds'] = $isOutOfBounds || $attendance->is_out_of_bounds;
            $updateData['status'] = AttendanceStatus::PendingApproval;
        }

        $attendance->update($updateData);

        if ($requiresApproval) {
            if ($attendance->needsNewApprovalRound()) {
                app(ApprovalFlowService::class)->generateSteps($attendance, 'geofence');
            }

            Notification::make()
                ->title('Check Out Dikirim (Menunggu Persetujuan)')
                ->body('Presensi Check Out sedang menunggu persetujuan atasan.')
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Check Out Berhasil!')
                ->body("Waktu Check Out: {$now->format('H:i:s')} WIB")
                ->success()
                ->send();
        }
    }
}
