<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AbsenHariIni;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Services\AnnualLeaveService;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
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

        // The employee's own month from the attendance ledger: a day worked counts once, also when it was
        // late or still waits for approval, and overtime is the automatic one plus approved requests.
        $month = app(AttendanceLedger::class)->summary(LedgerQuery::forWorkers(
            ['employee:'.$employee->id],
            today()->startOfMonth(),
            today()->endOfMonth(),
        ));

        $presentDays = $month['present_days'];
        $lateDays = $month['late_days'];

        $annualLeave = app(AnnualLeaveService::class)->summary($employee);

        $overtimeHours = round($month['overtime_minutes'] / 60, 1);

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
                ->description('Lembur otomatis dan lembur pengajuan disetujui')
                ->color('info')
                ->icon('heroicon-o-clock')
                ->url(OvertimeRequestResource::getUrl('index')),
        ];
    }
}
