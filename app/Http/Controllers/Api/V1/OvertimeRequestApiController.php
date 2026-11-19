<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOvertimeRequest;
use App\Http\Requests\Api\V1\UpdateOvertimeRequest;
use App\Http\Resources\Api\V1\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use App\Services\ApprovalFlowService;
use App\Services\OvertimeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OvertimeRequestApiController extends Controller
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

        $query = OvertimeRequest::query()->with(OvertimeRequest::workerEagerLoads());
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $requests = $query->withCount('approvalSteps')->latest()->paginate(15);

        return response()->json([
            'data' => OvertimeRequestResource::collection($requests),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(StoreOvertimeRequest $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;
        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $service = app(OvertimeRequestService::class);

        // Always derived from the two times: a past-midnight end moves to the next day, at most 12 hours.
        $durationMinutes = $service->validatedDuration($request->input('start_time'), $request->input('end_time'));

        // Saved together with its approval steps, so a missing approval flow leaves nothing behind. The worker
        // row is locked first, so two submissions sent at the same time cannot both pass the overlap check.
        $overtimeRequest = DB::transaction(function () use ($employee, $intern, $freelancer, $profile, $request, $durationMinutes, $service): OvertimeRequest {
            $service->lockWorker($profile);
            $service->assertNotOnLeave($profile, $request->input('date'));
            $service->assertNoOverlap($profile, $request->input('date'), $request->input('start_time'), $request->input('end_time'));

            $overtimeRequest = OvertimeRequest::create([
                'employee_id' => $employee?->id,
                'intern_id' => $intern?->id,
                'freelancer_id' => $freelancer?->id,
                'date' => $request->input('date'),
                'start_time' => $request->input('start_time'),
                'end_time' => $request->input('end_time'),
                'duration_minutes' => $durationMinutes,
                'reason' => $request->input('reason'),
                'status' => 'pending',
            ]);

            app(ApprovalFlowService::class)->generateSteps($overtimeRequest, 'overtime');

            return $overtimeRequest;
        });

        return response()->json([
            'message' => 'Pengajuan lembur berhasil dibuat',
            'data' => new OvertimeRequestResource($overtimeRequest),
        ], 201);
    }

    public function update(UpdateOvertimeRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;
        $intern = $user->intern;
        $freelancer = $user->freelancer;

        $profile = $employee ?? $intern ?? $freelancer;
        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $query = OvertimeRequest::query();
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $overtimeRequest = $query->find($id);

        if (! $overtimeRequest) {
            return response()->json(['message' => 'Pengajuan lembur tidak ditemukan.'], 404);
        }

        if ($overtimeRequest->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak dapat diubah.'], 422);
        }

        if (! ApprovalFlowService::isUntouchedByApprovers($overtimeRequest)) {
            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa diubah.'], 422);
        }

        $data = $request->only(['date', 'start_time', 'end_time', 'reason']);
        $service = app(OvertimeRequestService::class);

        // A time sent alone is combined with the stored other one: equal times or more than 12 hours are refused.
        $storedDate = $overtimeRequest->date->toDateString();
        $storedStart = substr((string) $overtimeRequest->start_time, 0, 5);
        $storedEnd = substr((string) $overtimeRequest->end_time, 0, 5);
        $date = isset($data['date']) ? Carbon::parse($data['date'])->toDateString() : $storedDate;
        $startTime = $data['start_time'] ?? $storedStart;
        $endTime = $data['end_time'] ?? $storedEnd;
        $dateChanged = $date !== $storedDate;
        $hoursChanged = $dateChanged || $startTime !== $storedStart || $endTime !== $storedEnd;

        // A new date must lie inside the window and the contract; a worker whose status ended edits nothing
        // (checked again below on the freshly locked worker row).
        $service->assertWithinContract($profile, $dateChanged ? $date : null, addressesWorker: true);

        if ($dateChanged) {
            $service->assertDateWithinWindow($date);
        }

        // Only a changed date or time is checked again, so the reason of an older request stays editable.
        if ($hoursChanged) {
            $data['duration_minutes'] = $service->validatedDuration($startTime, $endTime, isset($data['end_time']) ? 'end_time' : 'start_time');
        }

        // Re-checked under a row lock: an approver may have acted while the form was being filled in.
        $updated = DB::transaction(function () use ($overtimeRequest, $data, $profile, $service, $date, $startTime, $endTime, $dateChanged, $hoursChanged): bool {
            $service->assertWithinContract($service->lockWorker($profile), $dateChanged ? $date : null, addressesWorker: true);
            $locked = OvertimeRequest::query()->lockForUpdate()->find($overtimeRequest->getKey());

            if (! $locked || ! ApprovalFlowService::isUntouchedByApprovers($locked)) {
                return false;
            }

            if ($dateChanged) {
                $service->assertNotOnLeave($profile, $date);
            }

            if ($hoursChanged) {
                $service->assertNoOverlap($profile, $date, $startTime, $endTime, $overtimeRequest->getKey());
            }

            $overtimeRequest->update($data);

            return true;
        });

        if (! $updated) {
            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa diubah.'], 422);
        }

        return response()->json([
            'message' => 'Pengajuan lembur berhasil diperbarui',
            'data' => new OvertimeRequestResource($overtimeRequest),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $overtimeRequest = OvertimeRequest::with(['employee', 'intern', 'freelancer', 'approvalSteps'])->find($id);

        if (! $overtimeRequest) {
            return response()->json(['message' => 'Pengajuan lembur tidak ditemukan atau sudah dibatalkan.'], 404);
        }

        if (! ApprovalApiController::canOpen($overtimeRequest, $user)) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke pengajuan ini.'], 403);
        }

        return response()->json([
            'data' => new OvertimeRequestResource($overtimeRequest),
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

        $query = OvertimeRequest::query();
        if ($employee) {
            $query->where('employee_id', $employee->id);
        } elseif ($intern) {
            $query->where('intern_id', $intern->id);
        } elseif ($freelancer) {
            $query->where('freelancer_id', $freelancer->id);
        }

        $overtimeRequest = $query->find($id);

        if (! $overtimeRequest) {
            return response()->json(['message' => 'Pengajuan lembur tidak ditemukan.'], 404);
        }

        if ($overtimeRequest->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak dapat dibatalkan.'], 422);
        }

        if (! ApprovalFlowService::isUntouchedByApprovers($overtimeRequest)) {
            return response()->json(['message' => 'Pengajuan sudah mulai diproses, tidak bisa dibatalkan.'], 422);
        }

        // Re-checked under a row lock: an approver may have acted in the meantime.
        $cancelled = DB::transaction(function () use ($overtimeRequest): bool {
            $locked = OvertimeRequest::query()->lockForUpdate()->find($overtimeRequest->getKey());

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

        return response()->json([
            'message' => 'Pengajuan lembur berhasil dibatalkan.',
        ]);
    }
}
