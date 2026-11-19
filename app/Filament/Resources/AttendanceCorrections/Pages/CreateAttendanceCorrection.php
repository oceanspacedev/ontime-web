<?php

namespace App\Filament\Resources\AttendanceCorrections\Pages;

use App\Filament\Concerns\GeneratesApprovalSteps;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Models\AttendanceCorrection;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateAttendanceCorrection extends CreateRecord
{
    use GeneratesApprovalSteps;

    protected static string $resource = AttendanceCorrectionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $employee = $user?->employee;
        $intern = $user?->intern;
        $freelancer = $user?->freelancer;

        if (! $employee && ! $intern && ! $freelancer) {
            throw ValidationException::withMessages([
                'data.date' => 'Akun pengguna Anda belum terhubung ke data profil Karyawan/Magang.',
            ]);
        }

        if ($employee) {
            $data['employee_id'] = $employee->id;
        } elseif ($intern) {
            $data['intern_id'] = $intern->id;
        } elseif ($freelancer) {
            $data['freelancer_id'] = $freelancer->id;
        }

        $data['status'] = 'pending';
        $data['origin'] = AttendanceCorrection::ORIGIN_WORKER;

        $draft = new AttendanceCorrection($data);
        $errors = $draft->resolveTarget() ?: $draft->submissionErrors();

        if ($errors !== []) {
            throw ValidationException::withMessages(
                collect($errors)->mapWithKeys(fn (string $message, string $field): array => ["data.{$field}" => $message])->all(),
            );
        }

        return [...$data, ...$draft->targetAttributes()];
    }

    protected function afterCreate(): void
    {
        $this->generateApprovalStepsOrHalt('correction');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
