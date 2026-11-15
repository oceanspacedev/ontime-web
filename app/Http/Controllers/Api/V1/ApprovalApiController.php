<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProcessApprovalRequest;
use App\Http\Resources\Api\V1\AttendanceCorrectionResource;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Http\Resources\Api\V1\LeaveRequestResource;
use App\Http\Resources\Api\V1\OvertimeRequestResource;
use App\Http\Resources\Api\V1\ResignationResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Services\ApprovalFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        return response()->json([
            'leave_requests' => LeaveRequestResource::collection($leaves),
            'overtime_requests' => OvertimeRequestResource::collection($overtimes),
            'attendance_corrections' => AttendanceCorrectionResource::collection($corrections),
            'geofence_attendances' => AttendanceResource::collection($geofences),
            'resignations' => ResignationResource::collection($resignations),
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
            default => null,
        };

        if (! $modelClass) {
            return response()->json(['message' => 'Tipe pengajuan tidak valid. Harus salah satu dari: leave, overtime, correction, geofence, resignation.'], 422);
        }

        $requestModel = $modelClass::find($id);

        if (! $requestModel) {
            return response()->json(['message' => 'Data pengajuan tidak ditemukan.'], 404);
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
}
