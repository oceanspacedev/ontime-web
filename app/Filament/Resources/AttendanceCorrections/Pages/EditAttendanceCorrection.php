<?php

namespace App\Filament\Resources\AttendanceCorrections\Pages;

use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Models\AttendanceCorrection;
use Carbon\CarbonInterface;
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
     * The fields that decide what a correction writes: its date, times, target and the schedule it corrects.
     *
     * @var list<string>
     */
    private const DECIDING_FIELDS = ['date', 'corrected_check_in', 'corrected_check_out', 'corrected_shift_id', 'corrected_company_location_id'];

    /**
     * A correction edited in the panel is checked like a new one: no future date or time, a target that
     * can be told (the one stored at submission is kept while the date and times stay the same), no
     * check-out without a check-in, no attendance pending for the geofence approver, no
     * other correction of the same session. One already approved or rejected keeps what it decided: its
     * date, times and schedule can no longer change (only the reason and attachment).
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

        $draft = $record->replicate();
        $draft->id = $record->id;
        $draft->exists = true;
        $draft->fill(array_intersect_key($data, array_flip(self::DECIDING_FIELDS)));

        if ($record->status !== 'pending') {
            $changed = collect(self::DECIDING_FIELDS)->first(fn (string $field): bool => $this->differs($record, $draft, $field));

            if ($changed !== null) {
                throw ValidationException::withMessages(["data.{$changed}" => 'Pengajuan yang sudah diproses tidak dapat diubah tanggal, jam, atau jadwalnya. Ajukan koreksi baru bila perlu.']);
            }

            return $data;
        }

        // A changed check-out is placed by the rule again: the next-day flag of the old one does not carry over.
        if ($this->differs($record, $draft, 'corrected_check_out')) {
            $draft->check_out_next_day = false;
        }

        $errors = $draft->resolveTargetOnUpdate($record) ?: $draft->submissionErrors();

        if ($errors !== []) {
            throw ValidationException::withMessages(
                collect($errors)->mapWithKeys(fn (string $message, string $field): array => ["data.{$field}" => $message])->all(),
            );
        }

        return [...$data, ...$draft->targetAttributes(), 'check_out_next_day' => (bool) $draft->check_out_next_day];
    }

    /**
     * Whether the edited value of a deciding field differs from the stored one (times to the minute).
     */
    private function differs(AttendanceCorrection $record, AttendanceCorrection $draft, string $field): bool
    {
        $stored = $record->getAttribute($field);
        $edited = $draft->getAttribute($field);

        if ($stored instanceof CarbonInterface || $edited instanceof CarbonInterface) {
            $format = $field === 'date' ? 'Y-m-d' : 'H:i';

            return $stored?->format($format) !== $edited?->format($format);
        }

        return (string) $stored !== (string) $edited;
    }
}
