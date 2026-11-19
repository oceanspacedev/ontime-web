<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
use App\Services\DataAccessService;
use App\Support\StorageUrl;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * The attendance report: the summary in distinct worker-days from the attendance ledger
     * ({@see AttendanceLedger::summary()}, the figures the team recap totals to) and the attendance rows of
     * the same people, a page at a time.
     */
    public function laporanAbsensi(Request $request, AttendanceLedger $ledger): JsonResponse
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
        // The rows follow the summary: workers in the user's data access, and whoever worked at an outlet in it.
        // A half-open range: the last day still matches where dates are stored with a time (SQLite).
        $query = DataAccessService::scopeWorkerOrOutletRecords(Attendance::query(), $user, Attendance::workerRelations())
            ->where('date', '>=', $startDate)
            ->where('date', '<', Carbon::parse($endDate)->addDay()->toDateString())
            ->when($companyId, fn (Builder $query) => $query->where(fn (Builder $inCompany) => $inCompany
                ->whereIn('employee_id', Employee::query()->select('id')->where('company_id', $companyId))
                ->orWhereIn('intern_id', Intern::query()->select('id')->where('company_id', $companyId))
                ->orWhereIn('freelancer_id', Freelancer::query()->select('id')->where('company_id', $companyId))))
            ->with([...Attendance::workerEagerLoads(), 'employee.user', 'intern.user', 'freelancer.user']);

        // Each worker-day is counted once: present (late is a part of present), leave or absent.
        $summary = $ledger->summary(LedgerQuery::between($user, $startDate, $endDate, $companyId !== null ? (int) $companyId : null));

        $attendances = $query->latest('date')->paginate(30);

        return response()->json([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary' => [
                'total_present' => $summary['present_days'],
                'total_late' => $summary['late_days'],
                'total_leave' => $summary['leave_days'],
                'total_absent' => $summary['absent_days'],
                'total_pending' => $summary['pending_days'],
                'total_missing_check_out' => $summary['missing_check_out_days'],
                'total_worked_hours' => round($summary['worked_minutes'] / 60, 2),
                'total_overtime_hours' => round($summary['overtime_minutes'] / 60, 2),
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
}
