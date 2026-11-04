<?php

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Services\ApprovalFlowService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
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
        return (bool) (auth()->user()?->hasAnyRole(['Approver', 'BOD', 'Superadmin']) || auth()->user()?->canManageWorkers());
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

    /**
     * Approve, after a confirmation, like the Setujui action on the request lists.
     */
    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Setujui')
            ->color('success')
            ->size(Size::ExtraSmall)
            ->requiresConfirmation()
            ->modalHeading('Setujui pengajuan ini?')
            ->modalSubmitActionLabel('Setujui')
            ->action(fn (array $arguments) => $this->approveRequest((string) ($arguments['type'] ?? ''), (int) ($arguments['id'] ?? 0)));
    }

    /**
     * Reject with a required reason, like the Tolak action on the request lists, so the requester
     * learns why.
     */
    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Tolak')
            ->color('danger')
            ->outlined()
            ->size(Size::ExtraSmall)
            ->modalHeading('Tolak pengajuan')
            ->modalSubmitActionLabel('Tolak')
            ->schema([
                Textarea::make('reason')
                    ->label('Alasan Penolakan')
                    ->required()
                    ->maxLength(1000),
            ])
            ->action(fn (array $arguments, array $data) => $this->rejectRequest((string) ($arguments['type'] ?? ''), (int) ($arguments['id'] ?? 0), (string) $data['reason']));
    }

    protected function approveRequest(string $type, int $id): void
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

    protected function rejectRequest(string $type, int $id, string $reason): void
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
