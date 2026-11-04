<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportAndCalendarApiController extends Controller
{
    public function kalenderCuti(Request $request): JsonResponse
    {
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);
        $companyId = $request->input('company_id');

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

        $visibleCompanyIds = $request->user()->visibleCompanyIds();

        if ($visibleCompanyIds !== null) {
            $query->whereWorkerInCompanies($visibleCompanyIds);
        }

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
                'master_face_photo' => $person?->master_face_photo ?? null,
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
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $companyId = $request->input('company_id');

        $user = $request->user();
        $query = Attendance::with([...Attendance::workerEagerLoads(), 'employee.user', 'intern.user', 'freelancer.user'])
            ->whereBetween('date', [$startDate, $endDate]);

        if (! $user->can('ViewAny:Attendance')) {
            $query->byWorker($user);
        }

        if ($companyId) {
            $query->where(function ($q) use ($companyId) {
                $q->whereHas('employee', fn ($e) => $e->where('company_id', $companyId))
                    ->orWhereHas('intern', fn ($i) => $i->where('company_id', $companyId))
                    ->orWhereHas('freelancer', fn ($f) => $f->where('company_id', $companyId));
            });
        }

        $allRecords = (clone $query)->get();

        $totalPresent = $allRecords->filter(function ($a) {
            $val = $a->status instanceof \BackedEnum ? $a->status->value : (string) $a->status;

            return in_array($val, ['on_time', 'late', 'pending_approval']) || ! empty($a->check_in);
        })->count();

        // Hitung total berapa KALI terlambat (frekuensi, bukan total menit)
        $totalLateCount = $allRecords->filter(function ($a) {
            $val = $a->status instanceof \BackedEnum ? $a->status->value : (string) $a->status;

            return $val === 'late' || (int) $a->late_minutes > 0;
        })->count();

        $leaveQuery = LeaveRequest::where('status', 'approved')
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                    ->orWhereBetween('end_date', [$startDate, $endDate]);
            });

        if (! $user->can('ViewAny:Attendance')) {
            $leaveQuery->where(function ($q) use ($user) {
                if ($user->employee) {
                    $q->where('employee_id', $user->employee->id);
                } elseif ($user->intern) {
                    $q->where('intern_id', $user->intern->id);
                } elseif ($user->freelancer) {
                    $q->where('freelancer_id', $user->freelancer->id);
                } else {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        $approvedLeaveDates = [];
        $approvedLeaves = (clone $leaveQuery)->get();
        foreach ($approvedLeaves as $leave) {
            if ($leave->start_date && $leave->end_date) {
                $cur = Carbon::parse($leave->start_date);
                $lEnd = Carbon::parse($leave->end_date);
                while ($cur->lte($lEnd)) {
                    $approvedLeaveDates[] = $cur->toDateString();
                    $cur->addDay();
                }
            }
        }
        $totalLeave = count(array_unique($approvedLeaveDates));

        // Absen eksplisit dari record database
        $explicitAbsent = $allRecords->filter(function ($a) {
            $val = $a->status instanceof \BackedEnum ? $a->status->value : (string) $a->status;

            return in_array($val, ['absent', 'rejected']);
        })->count();

        // Otomatis hitung hari Senin-Jumat yang tidak terdeteksi presensi dan tidak ada cuti
        $existingDates = $allRecords->pluck('date')->map(function ($d) {
            return Carbon::parse($d)->toDateString();
        })->toArray();

        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $effectiveEnd = $end->isFuture() ? today() : $end;
        $unrecordedWeekdayAbsent = 0;

        if ($start->lte($effectiveEnd)) {
            $cursor = clone $start;
            while ($cursor->lte($effectiveEnd)) {
                if ($cursor->isWeekday()) {
                    $dateStr = $cursor->toDateString();
                    if (! in_array($dateStr, $existingDates) && ! in_array($dateStr, $approvedLeaveDates)) {
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
}
