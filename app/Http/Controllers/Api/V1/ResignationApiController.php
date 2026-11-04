<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ResignationResource;
use App\Models\Resignation;
use App\Services\ApprovalFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResignationApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $resignations = Resignation::with(Resignation::workerEagerLoads())
            ->visibleTo($request->user())
            ->latest()
            ->paginate(15);

        return response()->json([
            'data' => ResignationResource::collection($resignations),
            'pagination' => [
                'current_page' => $resignations->currentPage(),
                'last_page' => $resignations->lastPage(),
                'total' => $resignations->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Profil Karyawan tidak ditemukan.'], 422);
        }

        $existing = Resignation::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Anda sudah memiliki pengajuan pengunduran diri yang sedang diproses atau telah disetujui.'], 422);
        }

        $validated = $request->validate([
            'resignation_date' => 'required|date',
            'last_working_day' => 'required|date|after_or_equal:resignation_date',
            'reason' => 'required|string',
            'handover_notes' => 'nullable|string',
        ]);

        $resignation = Resignation::create([
            'employee_id' => $employee->id,
            'resignation_date' => $validated['resignation_date'],
            'last_working_day' => $validated['last_working_day'],
            'reason' => $validated['reason'],
            'handover_notes' => $validated['handover_notes'] ?? null,
            'status' => 'pending',
        ]);

        $service = new ApprovalFlowService;
        $service->generateSteps($resignation, 'resignation');

        return response()->json([
            'message' => 'Pengajuan pengunduran diri berhasil dibuat.',
            'data' => new ResignationResource($resignation),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $resignation = Resignation::with(Resignation::workerEagerLoads())->find($id);

        if (! $resignation) {
            return response()->json(['message' => 'Pengajuan pengunduran diri tidak ditemukan.'], 404);
        }

        if (! Resignation::query()->visibleTo($request->user())->whereKey($resignation->getKey())->exists()) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke pengajuan ini.'], 403);
        }

        return response()->json([
            'data' => new ResignationResource($resignation),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Profil Karyawan tidak ditemukan.'], 422);
        }

        $resignation = Resignation::where('employee_id', $employee->id)->find($id);

        if (! $resignation) {
            return response()->json(['message' => 'Pengajuan pengunduran diri tidak ditemukan.'], 404);
        }

        if ($resignation->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak dapat diubah.'], 422);
        }

        $validated = $request->validate([
            'resignation_date' => 'sometimes|date',
            'last_working_day' => 'sometimes|date|after_or_equal:resignation_date',
            'reason' => 'sometimes|string',
            'handover_notes' => 'nullable|string',
        ]);

        $resignation->update($validated);

        return response()->json([
            'message' => 'Pengajuan pengunduran diri berhasil diperbarui.',
            'data' => new ResignationResource($resignation),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['message' => 'Profil Karyawan tidak ditemukan.'], 422);
        }

        $resignation = Resignation::where('employee_id', $employee->id)->find($id);

        if (! $resignation) {
            return response()->json(['message' => 'Pengajuan pengunduran diri tidak ditemukan.'], 404);
        }

        if ($resignation->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak dapat dibatalkan.'], 422);
        }

        $resignation->approvalSteps()->delete();
        $resignation->delete();

        return response()->json([
            'message' => 'Pengajuan pengunduran diri berhasil dibatalkan.',
        ]);
    }
}
