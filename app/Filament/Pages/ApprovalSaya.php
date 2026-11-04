<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Services\ApprovalFlowService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

class ApprovalSaya extends Page
{
    protected static ?string $title = 'Persetujuan Masuk';

    protected static ?string $navigationLabel = 'Persetujuan Masuk';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Persetujuan (Inbox)';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.approval-saya';

    public static function canAccess(): bool
    {
        return (bool) (auth()->user()?->hasAnyRole(['Approver', 'BOD', 'Superadmin']) || auth()->user()?->canManageCompanies());
    }

    public function getPendingLeaveRequestsProperty(): Collection
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        $service = new ApprovalFlowService;

        return $service->getPendingRequestsForUser($user, LeaveRequest::class);
    }

    public function getPendingOvertimeRequestsProperty(): Collection
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        $service = new ApprovalFlowService;

        return $service->getPendingRequestsForUser($user, OvertimeRequest::class);
    }

    public function getPendingCorrectionsProperty(): Collection
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        $service = new ApprovalFlowService;

        return $service->getPendingRequestsForUser($user, AttendanceCorrection::class);
    }

    public function getPendingGeofenceAttendancesProperty(): Collection
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        $service = new ApprovalFlowService;

        return $service->getPendingRequestsForUser($user, Attendance::class);
    }

    public function getPendingResignationsProperty(): Collection
    {
        $user = auth()->user();
        if (! $user) {
            return collect();
        }

        $service = new ApprovalFlowService;

        return $service->getPendingRequestsForUser($user, Resignation::class);
    }

    public function approveRequest(string $type, int $id): void
    {
        $user = auth()->user();
        $service = new ApprovalFlowService;

        $item = match ($type) {
            'leave' => LeaveRequest::find($id),
            'overtime' => OvertimeRequest::find($id),
            'correction' => AttendanceCorrection::find($id),
            'geofence' => Attendance::find($id),
            'resignation' => Resignation::find($id),
            default => null,
        };

        if ($item) {
            $service->approveStep($item, $user);

            Notification::make()
                ->title('Pengajuan Berhasil Disetujui')
                ->success()
                ->send();
        }
    }

    public function rejectRequest(string $type, int $id, string $reason = 'Ditolak via Dashboard Approval'): void
    {
        $user = auth()->user();
        $service = new ApprovalFlowService;

        $item = match ($type) {
            'leave' => LeaveRequest::find($id),
            'overtime' => OvertimeRequest::find($id),
            'correction' => AttendanceCorrection::find($id),
            'geofence' => Attendance::find($id),
            'resignation' => Resignation::find($id),
            default => null,
        };

        if ($item) {
            $service->rejectStep($item, $user, $reason);

            Notification::make()
                ->title('Pengajuan Ditolak')
                ->danger()
                ->send();
        }
    }
}
