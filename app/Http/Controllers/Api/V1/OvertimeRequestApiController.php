<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOvertimeRequest;
use App\Http\Requests\Api\V1\UpdateOvertimeRequest;
use App\Http\Resources\Api\V1\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use App\Services\ApprovalFlowService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $startTime = Carbon::parse($request->input('start_time'));
        $endTime = Carbon::parse($request->input('end_time'));

        // Overtime that ends after midnight, same rule as the web form.
        if ($endTime->lessThanOrEqualTo($startTime)) {
            $endTime->addDay();
        }

        $durationMinutes = (int) $startTime->diffInMinutes($endTime);

        // Saved together with its approval steps, so a missing approval flow leaves nothing behind.
        $overtimeRequest = DB::transaction(function () use ($employee, $intern, $freelancer, $request, $durationMinutes): OvertimeRequest {
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

        $data = $request->only(['date', 'start_time', 'end_time', 'reason']);

        if (isset($data['start_time']) || isset($data['end_time'])) {
            $start = Carbon::parse($data['start_time'] ?? $overtimeRequest->start_time);
            $end = Carbon::parse($data['end_time'] ?? $overtimeRequest->end_time);

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            $data['duration_minutes'] = (int) $start->diffInMinutes($end);
        }

        $overtimeRequest->update($data);

        return response()->json([
            'message' => 'Pengajuan lembur berhasil diperbarui',
            'data' => new OvertimeRequestResource($overtimeRequest),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $overtimeRequest = OvertimeRequest::with(['employee', 'intern', 'freelancer', 'approvalSteps'])->findOrFail($id);

        if (! OvertimeRequest::query()->visibleTo($user)->whereKey($overtimeRequest->getKey())->exists()) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
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

        $overtimeRequest->approvalSteps()->delete();
        $overtimeRequest->delete();

        return response()->json([
            'message' => 'Pengajuan lembur berhasil dibatalkan.',
        ]);
    }
}
