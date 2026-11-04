<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ApprovalSaya;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Services\ApprovalFlowService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ApproverPendingWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasAnyRole(['Approver', 'BOD', 'Superadmin']) || $user->canManageWorkers());
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $service = new ApprovalFlowService;

        $pendingLeaves = $service->getPendingRequestsForUser($user, LeaveRequest::class)->count();
        $pendingOvertimes = $service->getPendingRequestsForUser($user, OvertimeRequest::class)->count();
        $pendingCorrections = $service->getPendingRequestsForUser($user, AttendanceCorrection::class)->count();
        $total = $pendingLeaves + $pendingOvertimes + $pendingCorrections;

        return [
            Stat::make('Antrean Approval Saya', "{$total} Item")
                ->description('Membutuhkan persetujuan Anda saat ini')
                ->color($total > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-inbox-stack')
                ->url(ApprovalSaya::getUrl()),

            Stat::make('Rincian Cuti & Lembur', "Cuti: {$pendingLeaves} | Lembur: {$pendingOvertimes}")
                ->description('Menunggu tindakan pada tahap Anda')
                ->color('info')
                ->icon('heroicon-o-clock')
                ->url(LeaveRequestResource::getUrl('index')),

            Stat::make('Koreksi Absensi Pending', "{$pendingCorrections} Request")
                ->description('Pengajuan koreksi jam absen')
                ->color('primary')
                ->icon('heroicon-o-document-check')
                ->url(AttendanceCorrectionResource::getUrl('index')),
        ];
    }
}
