<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Concerns\GeneratesApprovalSteps;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\AnnualLeaveService;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateLeaveRequest extends CreateRecord
{
    use GeneratesApprovalSteps;

    protected static string $resource = LeaveRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $employee = $user?->employee;
        $intern = $user?->intern;
        $freelancer = $user?->freelancer;

        // Resolve the active worker profile
        $workerProfile = $employee ?? $intern ?? $freelancer;

        // Any user with a worker profile may submit, including Approvers who are employees themselves.
        if (! $workerProfile) {
            throw ValidationException::withMessages([
                'data.leave_type' => 'Akun pengguna Anda belum terhubung ke data Karyawan, Magang, atau Freelance.',
            ]);
        }

        $startDate = Carbon::parse($data['start_date']);
        $endDate = Carbon::parse($data['end_date']);

        if ($endDate->lessThan($startDate)) {
            throw ValidationException::withMessages([
                'data.end_date' => 'Tanggal selesai harus sama atau setelah tanggal mulai.',
            ]);
        }

        if ($errors = LeaveRequest::periodErrors($startDate, $endDate)) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $message, string $field): array => ["data.{$field}" => $message])->all());
        }

        $leaveService = app(AnnualLeaveService::class);
        $daysCount = count($leaveService->chargedDates($workerProfile, $startDate, $endDate));

        if ($daysCount === 0) {
            throw ValidationException::withMessages(['data.start_date' => AnnualLeaveService::NO_WORKDAY_MESSAGE.'.']);
        }

        // Interns, freelancers and employees without annual leave cannot file it; others must fit their quota.
        // No leave of any type may share a day with another pending or approved leave.
        if ($error = $leaveService->validationError($workerProfile, $data['leave_type'] ?? null, $daysCount, startDate: $startDate, endDate: $endDate)
            ?? $leaveService->overlapError($workerProfile, $startDate, $endDate)) {
            throw ValidationException::withMessages(['data.leave_type' => $error]);
        }

        // Assign exactly one FK
        $data['employee_id'] = $employee?->id;
        $data['intern_id'] = $intern?->id;
        $data['freelancer_id'] = $freelancer?->id;
        $data['days_count'] = $daysCount;
        $data['status'] = 'pending';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->generateApprovalStepsOrHalt('leave');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
