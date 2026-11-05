<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Services\AnnualLeaveService;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
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
     * leave for someone without it or stretch it past the remaining quota. Changing only the reason
     * or attachment of an existing annual leave always saves.
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

        $daysCount = (int) $startDate->diffInDays($endDate) + 1;

        $error = app(AnnualLeaveService::class)->updateError($record, $data['leave_type'] ?? null, $daysCount);

        if ($error) {
            throw ValidationException::withMessages(['data.leave_type' => $error]);
        }

        $data['days_count'] = $daysCount;

        return $data;
    }
}
