<?php

namespace App\Filament\Resources\ResignationResource\Pages;

use App\Filament\Concerns\GeneratesApprovalSteps;
use App\Filament\Resources\ResignationResource;
use App\Models\Employee;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateResignation extends CreateRecord
{
    use GeneratesApprovalSteps;

    protected static string $resource = ResignationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        // Superadmin and Admin Badan Usaha may file for an employee they manage; everyone else only for themself.
        $onBehalf = filled($data['employee_id'] ?? null)
            && $user?->canManageCompany(Employee::query()->whereKey($data['employee_id'])->value('company_id'));

        if (! $onBehalf) {
            $data['employee_id'] = $user?->employee?->id;
        }

        if (empty($data['employee_id'])) {
            throw ValidationException::withMessages([
                'data.employee_id' => 'Silakan pilih Karyawan yang mengajukan resign.',
            ]);
        }

        $resignationDate = Carbon::parse($data['resignation_date']);
        $lastWorkingDay = Carbon::parse($data['last_working_day']);

        if ($lastWorkingDay->lessThan($resignationDate)) {
            throw ValidationException::withMessages([
                'data.last_working_day' => 'Hari kerja terakhir harus sama atau setelah tanggal pengajuan resign.',
            ]);
        }

        $data['status'] = 'pending';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->generateApprovalStepsOrHalt('resignation');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
