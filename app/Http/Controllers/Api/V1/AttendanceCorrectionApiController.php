<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAttendanceCorrectionRequest;
use App\Http\Requests\Api\V1\UpdateAttendanceCorrectionRequest;
use App\Http\Resources\Api\V1\AttendanceCorrectionResource;
use App\Models\AttendanceCorrection;
use App\Services\ApprovalFlowService;
use App\Services\FileNamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AttendanceCorrectionApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;

        if (! $profile) {
            return response()->json([
                'data' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                ],
            ]);
        }

        $query = AttendanceCorrection::query()->with(AttendanceCorrection::workerEagerLoads());
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $requests = $query->withCount('approvalSteps')->latest()->paginate(15);

        return response()->json([
            'data' => AttendanceCorrectionResource::collection($requests),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(StoreAttendanceCorrectionRequest $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;
        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $identifier = $profile->employee_code ?? $profile->nik ?? (string) $profile->id;
            $attachmentPath = FileNamingService::storeUploadedFile(
                $request->file('attachment'),
                'attendance-corrections/attachments',
                'CORR',
                $identifier,
                config('filesystems.default', 'public')
            );
        }

        // Saved together with its approval steps, so a missing approval flow leaves nothing behind,
        // not even the attachment stored above.
        try {
            $correction = DB::transaction(function () use ($employee, $intern, $freelancer, $request, $attachmentPath): AttendanceCorrection {
                $correction = AttendanceCorrection::create([
                    'employee_id' => $employee?->id,
                    'intern_id' => $intern?->id,
                    'freelancer_id' => $freelancer?->id,
                    'date' => $request->input('date'),
                    'corrected_check_in' => $request->input('corrected_check_in'),
                    'corrected_check_out' => $request->input('corrected_check_out'),
                    'reason' => $request->input('reason'),
                    'attachment' => $attachmentPath,
                    'status' => 'pending',
                ]);

                app(ApprovalFlowService::class)->generateSteps($correction, 'correction');

                return $correction;
            });
        } catch (Throwable $exception) {
            $this->deleteAttachment($attachmentPath);

            throw $exception;
        }

        return response()->json([
            'message' => 'Pengajuan koreksi absensi berhasil dibuat',
            'data' => new AttendanceCorrectionResource($correction),
        ], 201);
    }

    public function update(UpdateAttendanceCorrectionRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;
        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $query = AttendanceCorrection::query();
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $correction = $query->find($id);

        if (! $correction) {
            return response()->json(['message' => 'Pengajuan koreksi absensi tidak ditemukan.'], 404);
        }

        if ($correction->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak dapat diubah.'], 422);
        }

        if (! ApprovalFlowService::isUntouchedByApprovers($correction)) {
            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa diubah.'], 422);
        }

        $data = $request->only(['date', 'corrected_check_in', 'corrected_check_out', 'reason']);

        $previousAttachment = $correction->attachment;

        // Stored only once every check passed; the replaced file is removed once the change is saved.
        if ($request->hasFile('attachment')) {
            $identifier = $profile->employee_code ?? $profile->nik ?? (string) $profile->id;
            $data['attachment'] = FileNamingService::storeUploadedFile(
                $request->file('attachment'),
                'attendance-corrections/attachments',
                'CORR',
                $identifier,
                config('filesystems.default', 'public')
            );
        }

        // Re-checked under a row lock: an approver may have acted while the form was being filled in.
        try {
            $updated = DB::transaction(function () use ($correction, $data): bool {
                $locked = AttendanceCorrection::query()->lockForUpdate()->find($correction->getKey());

                if (! $locked || ! ApprovalFlowService::isUntouchedByApprovers($locked)) {
                    return false;
                }

                $correction->update($data);

                return true;
            });
        } catch (Throwable $exception) {
            $this->deleteAttachment($data['attachment'] ?? null);

            throw $exception;
        }

        if (! $updated) {
            $this->deleteAttachment($data['attachment'] ?? null);

            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa diubah.'], 422);
        }

        if (isset($data['attachment']) && $previousAttachment !== $data['attachment']) {
            $this->deleteAttachment($previousAttachment);
        }

        return response()->json([
            'message' => 'Pengajuan koreksi absensi berhasil diperbarui',
            'data' => new AttendanceCorrectionResource($correction),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $correction = AttendanceCorrection::with(['employee', 'intern', 'freelancer', 'approvalSteps'])->find($id);

        if (! $correction) {
            return response()->json(['message' => 'Pengajuan koreksi absensi tidak ditemukan atau sudah dibatalkan.'], 404);
        }

        if (! ApprovalApiController::canOpen($correction, $user)) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke pengajuan ini.'], 403);
        }

        return response()->json([
            'data' => new AttendanceCorrectionResource($correction),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;
        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $query = AttendanceCorrection::query();
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $correction = $query->find($id);

        if (! $correction) {
            return response()->json(['message' => 'Pengajuan koreksi absensi tidak ditemukan.'], 404);
        }

        if ($correction->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak dapat dibatalkan.'], 422);
        }

        if (! ApprovalFlowService::isUntouchedByApprovers($correction)) {
            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa dibatalkan.'], 422);
        }

        // Re-checked under a row lock: an approver may have acted in the meantime.
        $cancelled = DB::transaction(function () use ($correction): bool {
            $locked = AttendanceCorrection::query()->lockForUpdate()->find($correction->getKey());

            if (! $locked || ! ApprovalFlowService::isUntouchedByApprovers($locked)) {
                return false;
            }

            $locked->approvalSteps()->delete();
            $locked->delete();

            return true;
        });

        if (! $cancelled) {
            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa dibatalkan.'], 422);
        }

        // The cancelled request is gone, so its attachment has no owner left.
        $this->deleteAttachment($correction->attachment);

        return response()->json([
            'message' => 'Pengajuan koreksi absensi berhasil dibatalkan.',
        ]);
    }

    /**
     * Remove an attachment no request points to any more.
     */
    private function deleteAttachment(?string $path): void
    {
        if ($path) {
            Storage::disk(config('filesystems.default', 'public'))->delete($path);
        }
    }
}
