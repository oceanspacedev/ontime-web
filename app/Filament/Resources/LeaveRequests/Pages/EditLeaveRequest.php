<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\AnnualLeaveService;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditLeaveRequest extends EditRecord
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Applies the same rules as filing a new request, so an edit cannot turn a leave into annual
     * leave for someone without it, stretch it past the remaining quota, move it outside the
     * allowed period, onto the days of another leave or onto days the worker would not work.
     * Changing only the reason or attachment of an existing leave always saves and keeps its day
     * count. New dates are charged by the roster ({@see AnnualLeaveService::chargedDates()}).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var LeaveRequest $record */
        $record = $this->getRecord();

        $startDate = Carbon::parse($data['start_date'] ?? $record->start_date);
        $endDate = Carbon::parse($data['end_date'] ?? $record->end_date);

        if ($endDate->lessThan($startDate)) {
            throw ValidationException::withMessages([
                'data.end_date' => 'Tanggal selesai harus sama atau setelah tanggal mulai.',
            ]);
        }

        $datesChanged = $startDate->toDateString() !== $record->start_date->toDateString()
            || $endDate->toDateString() !== $record->end_date->toDateString();

        if ($datesChanged && $errors = LeaveRequest::periodErrors($startDate, $endDate)) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $message, string $field): array => ["data.{$field}" => $message])->all());
        }

        $leaveService = app(AnnualLeaveService::class);
        $profile = $record->getWorkerProfile();
        $daysCount = $datesChanged ? count($leaveService->chargedDates($profile, $startDate, $endDate)) : (int) $record->days_count;

        if ($datesChanged && $daysCount === 0) {
            throw ValidationException::withMessages(['data.start_date' => AnnualLeaveService::NO_WORKDAY_MESSAGE.'.']);
        }

        $error = $leaveService->updateError($record, $data['leave_type'] ?? null, $daysCount, $startDate, $endDate)
            ?? ($datesChanged && $profile ? $leaveService->overlapError($profile, $startDate, $endDate, $record->getKey()) : null);

        if ($error) {
            throw ValidationException::withMessages(['data.leave_type' => $error]);
        }

        $data['days_count'] = $daysCount;

        return $data;
    }

    /**
     * Saves the leave; an approved leave whose dates moved takes its leave rows along
     * ({@see LeaveRequest::syncAttendanceRows()}), all or nothing.
     *
     * @param  LeaveRequest  $record
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $previousStart = $record->start_date->toDateString();
        $previousEnd = $record->end_date->toDateString();
        $previousDates = $record->chargedDateStrings();

        try {
            return DB::transaction(function () use ($record, $data, $previousStart, $previousEnd, $previousDates): Model {
                $record->update($data);

                if ($record->status === 'approved'
                    && ($record->start_date->toDateString() !== $previousStart || $record->end_date->toDateString() !== $previousEnd)) {
                    $record->syncAttendanceRows($previousDates);
                }

                return $record;
            });
        } catch (ValidationException $exception) {
            $record->refresh();

            throw ValidationException::withMessages(['data.start_date' => collect($exception->errors())->flatten()->first()]);
        }
    }
}
