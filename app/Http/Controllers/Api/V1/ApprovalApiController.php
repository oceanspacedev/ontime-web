<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProcessApprovalRequest;
use App\Http\Resources\Api\V1\AttendanceCorrectionResource;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Http\Resources\Api\V1\LeaveRequestResource;
use App\Http\Resources\Api\V1\OvertimeRequestResource;
use App\Http\Resources\Api\V1\ResignationResource;
use App\Http\Resources\Api\V1\ShiftSwapRequestResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApprovalApiController extends Controller
{
    protected ApprovalFlowService $approvalService;

    public function __construct(ApprovalFlowService $approvalService)
    {
        $this->approvalService = $approvalService;
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $leaves = $this->approvalService->getPendingRequestsForUser($user, LeaveRequest::class);
        $overtimes = $this->approvalService->getPendingRequestsForUser($user, OvertimeRequest::class);
        $corrections = $this->approvalService->getPendingRequestsForUser($user, AttendanceCorrection::class);
        $geofences = $this->approvalService->getPendingRequestsForUser($user, Attendance::class);
        $resignations = $this->approvalService->getPendingRequestsForUser($user, Resignation::class);

        // A Tukar Shift whose date passed is kept out by getPendingRequestsForUser (shift-swaps:expire saves it).
        $shiftSwaps = $this->approvalService->getPendingRequestsForUser($user, ShiftSwapRequest::class);
        $shiftSwaps->load(['counterpartEmployee.division', 'counterpartIntern.division', 'counterpartFreelancer.division']);

        return response()->json([
            'leave_requests' => LeaveRequestResource::collection($leaves),
            'overtime_requests' => OvertimeRequestResource::collection($overtimes),
            'attendance_corrections' => AttendanceCorrectionResource::collection($corrections),
            'geofence_attendances' => AttendanceResource::collection($geofences),
            'resignations' => ResignationResource::collection($resignations),
            'shift_swaps' => ShiftSwapRequestResource::collection($shiftSwaps),
        ]);
    }

    public function process(ProcessApprovalRequest $request, string $type, int $id): JsonResponse
    {
        $user = $request->user();

        $modelClass = match ($type) {
            'leave', 'leave-requests' => LeaveRequest::class,
            'overtime', 'overtime-requests' => OvertimeRequest::class,
            'correction', 'attendance-corrections' => AttendanceCorrection::class,
            'geofence', 'attendance', 'attendances' => Attendance::class,
            'resignation', 'resignations' => Resignation::class,
            'shift_swap', 'shift-swaps' => ShiftSwapRequest::class,
            default => null,
        };

        if (! $modelClass) {
            return response()->json(['message' => 'Tipe pengajuan tidak valid. Harus salah satu dari: leave, overtime, correction, geofence, resignation, shift_swap.'], 422);
        }

        $requestModel = $modelClass::find($id);

        if (! $requestModel) {
            return response()->json(['message' => 'Data pengajuan tidak ditemukan.'], 404);
        }

        // Reach is checked before the status or stage is revealed: a request outside the user's reach gets
        // the very answer the service gives an approver without authority, so someone of another Klien learns
        // nothing about its progress. A user named on one of its stages still reaches it.
        if (! self::canOpen($requestModel, $user) && ! self::isNamedOnAStep($requestModel, $user)) {
            throw ValidationException::withMessages($request->input('action') === 'approved'
                ? ['approve' => 'Anda tidak memiliki hak akses untuk menyetujui tahap approval ini.']
                : ['reject' => 'Anda tidak memiliki hak akses untuk menolak tahap approval ini.']);
        }

        $statusValue = $requestModel->status instanceof \BackedEnum ? $requestModel->status->value : (string) $requestModel->status;
        if (! in_array($statusValue, ['pending', 'pending_approval'])) {
            return response()->json(['message' => 'Pengajuan ini sudah tidak berstatus pending.'], 422);
        }

        // A tap meant for the stage the approver saw must not decide a later stage that someone else
        // opened in the meantime (Admin and PIC may act on any stage).
        if ($request->filled('current_step') && (int) $request->input('current_step') !== (int) ($requestModel->current_step ?? 1)) {
            return response()->json([
                'message' => ApprovalFlowService::ALREADY_PROCESSED_MESSAGE,
                'errors' => ['request' => [ApprovalFlowService::ALREADY_PROCESSED_MESSAGE]],
            ], 422);
        }

        $action = $request->input('action');
        $note = $request->input('rejection_note');

        if ($action === 'approved') {
            $this->approvalService->approveStep($requestModel, $user, $note);
            $message = 'Pengajuan berhasil disetujui';
        } else {
            $this->approvalService->rejectStep($requestModel, $user, $note ?? 'Ditolak');
            $message = 'Pengajuan telah ditolak';
        }

        $requestModel->refresh();

        return response()->json([
            'message' => $message,
            'status' => $requestModel->status,
            'current_step' => $requestModel->current_step,
        ]);
    }

    /**
     * Whether the user may open a request's detail: it lies inside their data access, they may decide its
     * current stage (a named approver outside their data access still has to see what they decide), or
     * they already decided one of its stages.
     */
    public static function canOpen(Model $requestModel, User $user): bool
    {
        if ($requestModel::query()->visibleTo($user)->whereKey($requestModel->getKey())->exists()) {
            return true;
        }

        if (app(ApprovalFlowService::class)->isUserAuthorizedToApprove($requestModel, $user)) {
            return true;
        }

        return $requestModel->approvalSteps()->where('actioned_by', $user->id)->exists();
    }

    /**
     * Whether the user is the named approver of any stage of the request.
     */
    private static function isNamedOnAStep(Model $requestModel, User $user): bool
    {
        return $requestModel->approvalSteps()
            ->where('approver_type', 'user')
            ->where('user_id', $user->id)
            ->exists();
    }
}
