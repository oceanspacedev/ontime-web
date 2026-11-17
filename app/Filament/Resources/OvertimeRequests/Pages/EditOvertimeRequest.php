<?php

namespace App\Filament\Resources\OvertimeRequests\Pages;

use App\Filament\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use App\Services\ApprovalFlowService;
use App\Services\OvertimeRequestService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EditOvertimeRequest extends EditRecord
{
    protected static string $resource = OvertimeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * The duration is derived from the times again, never typed. Date and hours only change while no
     * approver has acted yet: an approved (or partly approved) overtime keeps its hours, the manager
     * rejects it and the worker files a new request, so changed hours always go through approval.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var OvertimeRequest $record */
        $record = $this->getRecord();
        $service = app(OvertimeRequestService::class);

        $date = $data['date'] ?? $record->date->toDateString();
        $startTime = substr((string) ($data['start_time'] ?? $record->start_time), 0, 5);
        $endTime = substr((string) ($data['end_time'] ?? $record->end_time), 0, 5);

        $dateChanged = $record->date->toDateString() !== Carbon::parse($date)->toDateString();
        $hoursChanged = $dateChanged
            || substr((string) $record->start_time, 0, 5) !== $startTime
            || substr((string) $record->end_time, 0, 5) !== $endTime;

        unset($data['actual_duration_minutes'], $data['duration_minutes'], $data['status'], $data['current_step']);

        if (! $hoursChanged) {
            return $data;
        }

        $worker = $record->getWorkerProfile();

        // Same lock order as the API (worker, then the overtime row), and the approval state is read from
        // the locked row: an approver acting at the same moment cannot approve hours they never saw.
        if ($worker !== null) {
            $worker = $service->lockWorker($worker);
        }

        $locked = OvertimeRequest::query()->lockForUpdate()->find($record->getKey());

        if ($locked === null || ! ApprovalFlowService::isUntouchedByApprovers($locked)) {
            throw ValidationException::withMessages([
                'data.start_time' => 'Tanggal dan jam lembur yang sudah diproses tidak bisa diubah. Tolak pengajuan ini lalu minta pekerja mengajukan ulang.',
            ]);
        }

        if ($worker !== null) {
            $service->assertWithinContract($worker, $dateChanged ? $date : null, 'data.date');
        }

        if ($dateChanged) {
            $service->assertDateWithinWindow($date, 'data.date');
        }

        $data['duration_minutes'] = $service->validatedDuration($startTime, $endTime, 'data.end_time');

        if ($worker !== null) {
            $service->assertNoOverlap($worker, $date, $startTime, $endTime, $record->getKey(), 'data.start_time');
        }

        return $data;
    }
}
