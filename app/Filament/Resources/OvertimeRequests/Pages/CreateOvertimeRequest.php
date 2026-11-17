<?php

namespace App\Filament\Resources\OvertimeRequests\Pages;

use App\Filament\Concerns\GeneratesApprovalSteps;
use App\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Services\OvertimeRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateOvertimeRequest extends CreateRecord
{
    use GeneratesApprovalSteps;

    protected static string $resource = OvertimeRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $employee = $user?->employee;
        $intern = $user?->intern;
        $freelancer = $user?->freelancer;

        $workerProfile = $employee ?? $intern ?? $freelancer;

        // Any user with a worker profile may submit, including Approvers who are employees themselves.
        if (! $workerProfile) {
            throw ValidationException::withMessages([
                'data.reason' => 'Akun pengguna Anda belum terhubung ke data Karyawan, Magang, atau Freelance.',
            ]);
        }

        $service = app(OvertimeRequestService::class);

        $service->assertDateWithinWindow($data['date'] ?? null, 'data.date');

        // Never a typed duration: always derived from the times, and nothing actual before approval.
        $data['duration_minutes'] = $service->validatedDuration($data['start_time'] ?? null, $data['end_time'] ?? null, 'data.end_time');

        // Locked inside the page's transaction, so a double submit cannot store two overlapping requests.
        $service->lockWorker($workerProfile);
        $service->assertNoOverlap($workerProfile, $data['date'], $data['start_time'], $data['end_time'], timeKey: 'data.start_time');

        unset($data['actual_duration_minutes']);

        $data['employee_id'] = $employee?->id;
        $data['intern_id'] = $intern?->id;
        $data['freelancer_id'] = $freelancer?->id;
        $data['status'] = 'pending';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->generateApprovalStepsOrHalt('overtime');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
