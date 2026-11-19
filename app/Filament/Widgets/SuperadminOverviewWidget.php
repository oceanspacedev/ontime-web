<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ApprovalSaya;
use App\Filament\Pages\LaporanAbsensi;
use App\Filament\Resources\CompanyResource;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\Attendance\LedgerQuery;
use App\Services\AttendanceLedger;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SuperadminOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    /** The attendance figures read every worker of today, so the widget refreshes once a minute. */
    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasRole('Superadmin');
    }

    protected function getStats(): array
    {
        $today = today();

        // Today in distinct worker-days from the attendance ledger, the figures every report gives:
        // present includes the late and the ones waiting for approval.
        $summary = app(AttendanceLedger::class)->summary(LedgerQuery::between(auth()->user(), $today, $today));
        $presentCount = $summary['present_days'];
        $lateCount = $summary['late_days'];
        $leaveCount = $summary['leave_days'];

        $pendingLeaves = LeaveRequest::where('status', 'pending')->count();
        $pendingOvertimes = OvertimeRequest::where('status', 'pending')->count();
        $pendingCorrections = AttendanceCorrection::where('status', 'pending')->count();
        $totalPending = $pendingLeaves + $pendingOvertimes + $pendingCorrections;

        $totalCompanies = Company::where('is_active', true)->count();

        return [
            Stat::make('Perusahaan Aktif', "{$totalCompanies} Badan Usaha")
                ->description('Total perusahaan terdaftar dalam sistem')
                ->color('primary')
                ->icon('heroicon-o-building-office-2')
                ->url(CompanyResource::getUrl('index')),

            Stat::make('Hadir Hari Ini', "{$presentCount} Karyawan")
                ->description("Telat: {$lateCount} | Cuti/Izin: {$leaveCount}")
                ->color('success')
                ->icon('heroicon-o-user-group')
                ->url(LaporanAbsensi::getUrl()),

            Stat::make('Pending Approval Lintas Company', "{$totalPending} Pengajuan")
                ->description("Cuti: {$pendingLeaves} | Lembur: {$pendingOvertimes} | Koreksi: {$pendingCorrections}")
                ->color($totalPending > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-inbox-stack')
                ->url(ApprovalSaya::getUrl()),
        ];
    }
}
