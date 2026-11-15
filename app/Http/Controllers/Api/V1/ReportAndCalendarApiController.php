<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Support\StorageUrl;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportAndCalendarApiController extends Controller
{
    public function kalenderCuti(Request $request): JsonResponse
    {
        // Same rule as the Kalender Cuti Tim page: interns do not see the team leave calendar.
        if ($request->user()->intern !== null) {
            return response()->json(['message' => 'Kalender cuti tim tidak tersedia untuk peserta magang.'], 403);
        }

        $validated = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $month = (int) ($validated['month'] ?? now()->month);
        $year = (int) ($validated['year'] ?? now()->year);
        $companyId = $validated['company_id'] ?? null;

        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $endOfMonth = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $query = LeaveRequest::with(['employee.company', 'employee.division', 'intern.company', 'intern.division', 'freelancer.company', 'freelancer.division'])
            ->whereIn('status', ['approved', 'pending'])
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('start_date', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('end_date', [$startOfMonth, $endOfMonth])
                    ->orWhere(function ($q2) use ($startOfMonth, $endOfMonth) {
                        $q2->where('start_date', '<=', $startOfMonth)
                            ->where('end_date', '>=', $endOfMonth);
                    });
            });

        if ($companyId) {
            $query->where(function ($q) use ($companyId) {
                $q->whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
                    ->orWhereHas('intern', fn ($i) => $i->where('company_id', $companyId))
                    ->orWhereHas('freelancer', fn ($f) => $f->where('company_id', $companyId));
            });
        }

        $query->visibleWithColleaguesTo($request->user());

        $leaveRequests = $query->orderBy('start_date', 'asc')->get()->map(function ($leave) {
            $person = $leave->employee ?? $leave->intern ?? $leave->freelancer;

            return [
                'id' => $leave->id,
                'person_name' => $person?->full_name ?? 'Pengguna',
                'user_name' => $person?->full_name ?? 'Pengguna',
                'company_name' => $person?->company?->name,
                'division_name' => $person?->division?->name,
                'department' => $person?->division?->name ?? $person?->company?->name ?? 'Tim',
                'leave_type' => $leave->leave_type,
                'start_date' => $leave->start_date?->format('Y-m-d'),
                'end_date' => $leave->end_date?->format('Y-m-d'),
                'days_count' => $leave->days_count,
                'status' => $leave->status,
                'reason' => $leave->reason,
                'master_face_photo' => StorageUrl::for($person?->master_face_photo),
            ];
        });

        return response()->json([
            'month' => $month,
            'year' => $year,
            'data' => $leaveRequests,
        ]);
    }

    public function laporanAbsensi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'company_id' => ['nullable', 'integer'],
        ]);

        $startDate = $validated['start_date'] ?? now()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? now()->toDateString();
        $companyId = $validated['company_id'] ?? null;

        if (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) > 366) {
            return response()->json(['message' => 'Rentang tanggal maksimal 366 hari.', 'errors' => ['end_date' => ['Rentang tanggal maksimal 366 hari.']]], 422);
        }

        $user = $request->user();
        // A half-open range: the last day still matches where dates are stored with a time (SQLite).
        $scoped = Attendance::query()
            ->where('date', '>=', $startDate)
            ->where('date', '<', Carbon::parse($endDate)->addDay()->toDateString())
            ->visibleTo($user)
            ->when($companyId, fn (Builder $query) => $query->where(fn (Builder $inCompany) => $inCompany
                ->whereIn('employee_id', Employee::query()->select('id')->where('company_id', $companyId))
                ->orWhereIn('intern_id', Intern::query()->select('id')->where('company_id', $companyId))
                ->orWhereIn('freelancer_id', Freelancer::query()->select('id')->where('company_id', $companyId))));
        $query = (clone $scoped)->with([...Attendance::workerEagerLoads(), 'employee.user', 'intern.user', 'freelancer.user']);

        $summary = $this->attendanceSummary($scoped);
        $totalPresent = $summary['present'];
        // Hitung total berapa KALI terlambat (frekuensi, bukan total menit)
        $totalLateCount = $summary['late'];

        $leaveQuery = LeaveRequest::where('status', 'approved')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate]);
            });

        $leaveQuery->visibleTo($user)
            ->when($companyId, fn ($q) => $q->whereWorkerInCompanies([(int) $companyId]));

        $approvedLeaveDates = [];
        $approvedLeaves = (clone $leaveQuery)->toBase()->get(['start_date', 'end_date']);
        foreach ($approvedLeaves as $leave) {
            if ($leave->start_date && $leave->end_date) {
                $cur = Carbon::parse($leave->start_date)->startOfDay();
                $lEnd = Carbon::parse($leave->end_date)->startOfDay();
                while ($cur->lte($lEnd)) {
                    $approvedLeaveDates[$cur->toDateString()] = true;
                    $cur->addDay();
                }
            }
        }
        $totalLeave = count($approvedLeaveDates);

        // Absen eksplisit dari record database
        $explicitAbsent = $summary['absent'];

        // Otomatis hitung hari Senin-Jumat yang tidak terdeteksi presensi dan tidak ada cuti
        $existingDates = (clone $scoped)->toBase()->distinct()->pluck('date')
            ->mapWithKeys(fn ($date): array => [Carbon::parse($date)->toDateString() => true])
            ->all();

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $effectiveEnd = $end->isFuture() ? today() : $end;
        $unrecordedWeekdayAbsent = 0;

        if ($start->lte($effectiveEnd)) {
            $cursor = clone $start;
            while ($cursor->lte($effectiveEnd)) {
                if ($cursor->isWeekday()) {
                    $dateStr = $cursor->toDateString();
                    if (! isset($existingDates[$dateStr]) && ! isset($approvedLeaveDates[$dateStr])) {
                        $unrecordedWeekdayAbsent++;
                    }
                }
                $cursor->addDay();
            }
        }

        $totalAbsent = $explicitAbsent + $unrecordedWeekdayAbsent;

        $attendances = $query->latest('date')->paginate(30);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_present' => $totalPresent,
                'total_late' => $totalLateCount,
                'total_leave' => $totalLeave,
                'total_absent' => $totalAbsent,
            ],
            'data' => AttendanceResource::collection($attendances->items()),
            'pagination' => [
                'current_page' => $attendances->currentPage(),
                'last_page' => $attendances->lastPage(),
                'per_page' => $attendances->perPage(),
                'total' => $attendances->total(),
            ],
        ]);
    }

    /**
     * Present and late worker-days, and explicit absences, in one aggregate query. A split shift has one
     * row per segment: a worker's day counts once, however many segments it has.
     *
     * @param  Builder<Attendance>  $scoped
     * @return array{present: int, late: int, absent: int}
     */
    private function attendanceSummary(Builder $scoped): array
    {
        $perWorkerDay = (clone $scoped)->toBase()
            ->selectRaw("MAX(CASE WHEN status IN ('on_time', 'late', 'pending_approval') OR check_in IS NOT NULL THEN 1 ELSE 0 END) AS is_present")
            ->selectRaw("MAX(CASE WHEN status = 'late' OR late_minutes > 0 THEN 1 ELSE 0 END) AS is_late")
            ->selectRaw("SUM(CASE WHEN status IN ('absent', 'rejected') THEN 1 ELSE 0 END) AS absent_rows")
            ->groupBy('employee_id', 'intern_id', 'freelancer_id', 'date');

        $totals = DB::query()
            ->fromSub($perWorkerDay, 'worker_days')
            ->selectRaw('COALESCE(SUM(is_present), 0) AS present, COALESCE(SUM(is_late), 0) AS late, COALESCE(SUM(absent_rows), 0) AS absent')
            ->first();

        return [
            'present' => (int) ($totals->present ?? 0),
            'late' => (int) ($totals->late ?? 0),
            'absent' => (int) ($totals->absent ?? 0),
        ];
    }
}
