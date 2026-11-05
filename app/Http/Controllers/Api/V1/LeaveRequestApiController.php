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
        $daysCount = (int) $startDate->diffInDays($endDate) + 1;

        if ($error = app(AnnualLeaveService::class)->validationError($profile, $request->input('leave_type'), $daysCount)) {
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
                'public'
            );
        }

        // Saved together with its approval steps, so a missing approval flow leaves nothing behind.
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

        $data = $request->only(['leave_type', 'start_date', 'end_date', 'reason']);

        if ($request->hasFile('attachment')) {
            $identifier = $profile->employee_code ?? $profile->nik ?? (string) $profile->id;
            $data['attachment'] = FileNamingService::storeUploadedFile(
                $request->file('attachment'),
                'leave-requests/attachments',
                'LEAVE',
                $identifier,
                'public'
            );
        }

        if (isset($data['start_date']) || isset($data['end_date'])) {
            $start = Carbon::parse($data['start_date'] ?? $leaveRequest->start_date);
            $end = Carbon::parse($data['end_date'] ?? $leaveRequest->end_date);

            if ($end->lessThan($start)) {
                return response()->json(['message' => 'Tanggal selesai harus sama atau setelah tanggal mulai.', 'errors' => ['end_date' => ['Tanggal selesai harus sama atau setelah tanggal mulai.']]], 422);
            }

            $data['days_count'] = (int) $start->diffInDays($end) + 1;
        }

        if ($error = app(AnnualLeaveService::class)->updateError($leaveRequest, $data['leave_type'] ?? null, (int) ($data['days_count'] ?? $leaveRequest->days_count))) {
            return response()->json(['message' => $error, 'errors' => ['leave_type' => [$error]]], 422);
        }

        $leaveRequest->update($data);

        return response()->json([
            'message' => 'Pengajuan cuti berhasil diperbarui',
            'data' => new LeaveRequestResource($leaveRequest),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $leaveRequest = LeaveRequest::with(['employee', 'intern', 'freelancer', 'approvalSteps'])->findOrFail($id);

        if (! LeaveRequest::query()->visibleTo($user)->whereKey($leaveRequest->getKey())->exists()) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
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

        $leaveRequest->approvalSteps()->delete();
        $leaveRequest->delete();

        return response()->json([
            'message' => 'Pengajuan cuti berhasil dibatalkan.',
        ]);
    }
}
