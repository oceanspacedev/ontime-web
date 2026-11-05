<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AbsenHariIni;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Models\Attendance;
use App\Models\OvertimeRequest;
use App\Services\AnnualLeaveService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EmployeeStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->employee;
    }

    protected function getStats(): array
    {
        $employee = auth()->user()?->employee;
        if (! $employee) {
            return [];
        }

        $currentMonth = now()->month;
        $currentYear = now()->year;

        $attendanceStats = Attendance::where('employee_id', $employee->id)
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->selectRaw("
                SUM(CASE WHEN status IN ('on_time', 'present') THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
            ")
            ->first();

        $presentDays = (int) ($attendanceStats?->present_days ?? 0);
        $lateDays = (int) ($attendanceStats?->late_days ?? 0);

        $annualLeave = app(AnnualLeaveService::class)->summary($employee);

        $overtimeMinutes = (int) OvertimeRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('duration_minutes');

        $overtimeHours = round($overtimeMinutes / 60, 1);

        return [
            Stat::make('Kehadiran Bulan Ini', "{$presentDays} Hari")
                ->description("Telat: {$lateDays} hari bulan ini")
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->url(AbsenHariIni::getUrl()),

            $annualLeave['eligible']
                ? Stat::make('Kuota Cuti Tahunan', "Sisa {$annualLeave['remaining_days']} Hari")
                    ->description("Terpakai: {$annualLeave['used_days']} dari {$annualLeave['annual_leave_quota']} hari")
                    ->color($annualLeave['remaining_days'] > 2 ? 'primary' : 'warning')
                    ->icon('heroicon-o-calendar')
                    ->url(LeaveRequestResource::getUrl('index'))
                : Stat::make('Cuti Tahunan', 'Tidak ada jatah')
                    ->description('Hubungi HR bila Anda seharusnya mendapat cuti tahunan')
                    ->color('gray')
                    ->icon('heroicon-o-calendar')
                    ->url(LeaveRequestResource::getUrl('index')),

            Stat::make('Lembur Bulan Ini', "{$overtimeHours} Jam")
                ->description('Total durasi lembur disetujui')
                ->color('info')
                ->icon('heroicon-o-clock')
                ->url(OvertimeRequestResource::getUrl('index')),
        ];
    }
}
