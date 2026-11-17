<?php

namespace App\Filament\Resources\AttendanceCorrections\Pages;

use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Models\AttendanceCorrection;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditAttendanceCorrection extends EditRecord
{
    protected static string $resource = AttendanceCorrectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * A pending correction edited in the panel is checked like a new one: no future date or time, no
     * attendance pending or refused by the geofence approver, no other correction of the same date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var AttendanceCorrection $record */
        $record = $this->getRecord();

        if ($record->status !== 'pending') {
            return $data;
        }

        $draft = $record->replicate();
        $draft->id = $record->id;
        $draft->exists = true;
        $draft->fill(array_intersect_key($data, array_flip(['date', 'corrected_check_in', 'corrected_check_out'])));

        $errors = $draft->submissionErrors();

        if ($errors !== []) {
            throw ValidationException::withMessages(
                collect($errors)->mapWithKeys(fn (string $message, string $field): array => ["data.{$field}" => $message])->all(),
            );
        }

        return $data;
    }
}
