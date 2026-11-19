<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLeaveRequest;
use App\Http\Requests\Api\V1\UpdateLeaveRequest;
use App\Http\Resources\Api\V1\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\AnnualLeaveService;
use App\Services\ApprovalFlowService;
use App\Services\FileNamingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LeaveRequestApiController extends Controller
{
    public function index(Request $request, AnnualLeaveService $annualLeave): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;

        if (! $profile) {
            return response()->json([
                'quota_summary' => $annualLeave->summary(null),
                'data' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                ],
            ]);
        }

        $query = LeaveRequest::query()->with(LeaveRequest::workerEagerLoads());
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $requests = $query->withCount('approvalSteps')->latest()->paginate(15);

        return response()->json([
            'quota_summary' => $annualLeave->summary($profile),
            'data' => LeaveRequestResource::collection($requests),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;
        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $startDate = Carbon::parse($request->input('start_date'));
        $endDate = Carbon::parse($request->input('end_date'));
        $daysCount = count(app(AnnualLeaveService::class)->chargedDates($profile, $startDate, $endDate));

        if ($daysCount === 0) {
            return $this->noWorkdayResponse();
        }

        if ($error = app(AnnualLeaveService::class)->validationError($profile, $request->input('leave_type'), $daysCount, startDate: $startDate, endDate: $endDate)) {
            return response()->json(['message' => $error, 'errors' => ['leave_type' => [$error]]], 422);
        }

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $identifier = $profile->employee_code ?? $profile->nik ?? (string) $profile->id;
            $attachmentPath = FileNamingService::storeUploadedFile(
                $request->file('attachment'),
                'leave-requests/attachments',
                'LEAVE',
                $identifier,
                config('filesystems.default')
            );
        }

        // Saved together with its approval steps, so a missing approval flow leaves nothing behind,
        // not even the attachment stored above.
        try {
            $leaveRequest = DB::transaction(function () use ($employee, $intern, $freelancer, $request, $daysCount, $attachmentPath): LeaveRequest {
                $leaveRequest = LeaveRequest::create([
                    'employee_id' => $employee?->id,
                    'intern_id' => $intern?->id,
                    'freelancer_id' => $freelancer?->id,
                    'leave_type' => $request->input('leave_type'),
                    'start_date' => $request->input('start_date'),
                    'end_date' => $request->input('end_date'),
                    'days_count' => $daysCount,
                    'reason' => $request->input('reason'),
                    'attachment' => $attachmentPath,
                    'status' => 'pending',
                ]);

                app(ApprovalFlowService::class)->generateSteps($leaveRequest, 'leave');

                return $leaveRequest;
            });
        } catch (Throwable $exception) {
            $this->deleteAttachment($attachmentPath);

            throw $exception;
        }

        return response()->json([
            'message' => 'Pengajuan cuti berhasil dibuat',
            'data' => new LeaveRequestResource($leaveRequest),
        ], 201);
    }

    public function update(UpdateLeaveRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;
        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $query = LeaveRequest::query();
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $leaveRequest = $query->find($id);

        if (! $leaveRequest) {
            return response()->json(['message' => 'Pengajuan cuti tidak ditemukan.'], 404);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak dapat diubah.'], 422);
        }

        if (! ApprovalFlowService::isUntouchedByApprovers($leaveRequest)) {
            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa diubah.'], 422);
        }

        $data = $request->only(['leave_type', 'start_date', 'end_date', 'reason']);

        if (isset($data['start_date']) || isset($data['end_date'])) {
            $start = Carbon::parse($data['start_date'] ?? $leaveRequest->start_date);
            $end = Carbon::parse($data['end_date'] ?? $leaveRequest->end_date);

            if ($end->lessThan($start)) {
                return response()->json(['message' => 'Tanggal selesai harus sama atau setelah tanggal mulai.', 'errors' => ['end_date' => ['Tanggal selesai harus sama atau setelah tanggal mulai.']]], 422);
            }

            $data['days_count'] = count(app(AnnualLeaveService::class)->chargedDates($leaveRequest->getWorkerProfile() ?? $profile, $start, $end));

            if ($data['days_count'] === 0) {
                return $this->noWorkdayResponse();
            }
        }

        if ($error = app(AnnualLeaveService::class)->updateError($leaveRequest, $data['leave_type'] ?? null, (int) ($data['days_count'] ?? $leaveRequest->days_count), $start ?? null, $end ?? null)) {
            return response()->json(['message' => $error, 'errors' => ['leave_type' => [$error]]], 422);
        }

        $previousAttachment = $leaveRequest->attachment;

        // Stored only once every check passed; the replaced file is removed once the change is saved.
        if ($request->hasFile('attachment')) {
            $identifier = $profile->employee_code ?? $profile->nik ?? (string) $profile->id;
            $data['attachment'] = FileNamingService::storeUploadedFile(
                $request->file('attachment'),
                'leave-requests/attachments',
                'LEAVE',
                $identifier,
                config('filesystems.default')
            );
        }

        // Re-checked under a row lock: an approver may have acted while the form was being filled in.
        try {
            $updated = DB::transaction(function () use ($leaveRequest, $data): bool {
                $locked = LeaveRequest::query()->lockForUpdate()->find($leaveRequest->getKey());

                if (! $locked || ! ApprovalFlowService::isUntouchedByApprovers($locked)) {
                    return false;
                }

                $leaveRequest->update($data);

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
            'message' => 'Pengajuan cuti berhasil diperbarui',
            'data' => new LeaveRequestResource($leaveRequest),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $leaveRequest = LeaveRequest::with(['employee', 'intern', 'freelancer', 'approvalSteps'])->find($id);

        if (! $leaveRequest) {
            return response()->json(['message' => 'Pengajuan cuti tidak ditemukan atau sudah dibatalkan.'], 404);
        }

        if (! ApprovalApiController::canOpen($leaveRequest, $user)) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke pengajuan ini.'], 403);
        }

        return response()->json([
            'data' => new LeaveRequestResource($leaveRequest),
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

        $query = LeaveRequest::query();
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $leaveRequest = $query->find($id);

        if (! $leaveRequest) {
            return response()->json(['message' => 'Pengajuan cuti tidak ditemukan.'], 404);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak dapat dibatalkan.'], 422);
        }

        if (! ApprovalFlowService::isUntouchedByApprovers($leaveRequest)) {
            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa dibatalkan.'], 422);
        }

        // Re-checked under a row lock: an approver may have acted in the meantime.
        $cancelled = DB::transaction(function () use ($leaveRequest): bool {
            $locked = LeaveRequest::query()->lockForUpdate()->find($leaveRequest->getKey());

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
        $this->deleteAttachment($leaveRequest->attachment);

        return response()->json([
            'message' => 'Pengajuan cuti berhasil dibatalkan.',
        ]);
    }

    /**
     * Refusal for a period in which the worker would not work a single day (only rostered days off).
     */
    private function noWorkdayResponse(): JsonResponse
    {
        $message = AnnualLeaveService::NO_WORKDAY_MESSAGE.'.';

        return response()->json(['message' => $message, 'errors' => ['start_date' => [$message]]], 422);
    }

    /**
     * Remove an attachment no request points to any more.
     */
    private function deleteAttachment(?string $path): void
    {
        if ($path) {
            Storage::disk(config('filesystems.default'))->delete($path);
        }
    }
}
