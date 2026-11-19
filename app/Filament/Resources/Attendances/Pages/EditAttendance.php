<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Services\AttendanceLatenessService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Livewire\Attributes\Locked;

class EditAttendance extends EditRecord
{
    protected static string $resource = AttendanceResource::class;

    /** Whether this save moves the check-in or check-out to another minute. */
    private bool $timesMoved = false;

    /**
     * The audited values of the row before this save, when it changes times, status or lateness
     * ({@see Attendance::auditValues()}); null when the save changes none of them.
     *
     * @var array<string, mixed>|null
     */
    private ?array $auditBefore = null;

    /**
     * The columns a check-in, check-out, approval or another admin can change while this form is open.
     *
     * @var list<string>
     */
    private const WATCHED_COLUMNS = [
        'shift_id', 'check_in', 'check_out', 'status', 'late_minutes', 'early_leave_minutes',
        'notes', 'is_corrected', 'status_overridden',
        ...Attendance::SNAPSHOT_COLUMNS,
    ];

    /**
     * The row as it was when the form was opened, to refuse a save made from a stale form.
     *
     * @var array<string, string|null>
     */
    #[Locked]
    public array $openedState = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (Attendance $record): bool => AttendanceResource::deleteWithAudit($record)),
        ];
    }

    protected function afterFill(): void
    {
        $this->openedState = $this->currentState(lock: false);
    }

    /**
     * A save from a form opened before the worker checked out, an approver acted or another admin saved
     * would write the old values back over theirs (an empty check-out reads as a moved time), so it is
     * refused and the admin reopens the row.
     *
     * @throws Halt
     */
    protected function beforeSave(): void
    {
        if ($this->currentState(lock: true) === $this->openedState) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Absensi ini sudah berubah sejak formulir dibuka')
            ->body('Check-in, check-out atau statusnya diperbarui oleh pekerja, approver atau admin lain. Muat ulang halaman lalu ulangi perubahan Anda.')
            ->persistent()
            ->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    /**
     * The watched columns of the row as stored, compared as plain strings.
     *
     * @return array<string, string|null>
     */
    private function currentState(bool $lock): array
    {
        $query = $this->getRecord()->newQuery()->toBase()->where($this->getRecord()->getKeyName(), $this->getRecord()->getKey());

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = (array) ($query->first(self::WATCHED_COLUMNS) ?? []);

        return array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $row);
    }

    /**
     * An override that moves the check-in or check-out to another minute marks the row as
     * corrected, so reports and the worker see that the times are not the ones recorded on site.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['check_in', 'check_out'] as $column) {
            if (array_key_exists($column, $data)
                && $this->minuteOf($data[$column]) !== $this->minuteOf($this->getRecord()->getAttribute($column))) {
                $data['is_corrected'] = true;
                $this->timesMoved = true;
            }
        }

        $verdictChanged = $this->verdictChanged($data);

        // Only the verdict was edited (for example a late arrival excused): it is the admin's call, which a
        // later roster change on that date must not overwrite.
        if (! $this->timesMoved && $verdictChanged) {
            $data['status_overridden'] = true;
        }

        // Every change of times, status or lateness is recorded as an admin correction (with the values
        // it replaced), in the same transaction as the save.
        $this->auditBefore = $this->timesMoved || $verdictChanged ? $this->getRecord()->auditValues() : null;

        return $data;
    }

    /**
     * Moved times are judged again against the row's own schedule (its snapshot): lateness and status (or
     * the verdict of a row waiting for approval) and early leave follow the new times instead of keeping the
     * old verdict, even one an admin set by hand before. A row without a snapshot yet gets one.
     */
    protected function afterSave(): void
    {
        if ($this->timesMoved) {
            $this->timesMoved = false;
            $lateness = app(AttendanceLatenessService::class);
            $lateness->recalculate($this->getRecord(), force: true);
            $lateness->writeMissingSnapshot($this->getRecord());
        }

        // A row whose check-in the admin cleared no longer keeps the schedule it was checked in to.
        /** @var Attendance $edited */
        $edited = $this->getRecord();

        if ($edited->check_in === null && $edited->hasSnapshot()) {
            $edited->forceFill(array_fill_keys(array_diff(Attendance::SNAPSHOT_COLUMNS, ['schedule_sequence']), null))->saveQuietly();
        }

        if ($this->auditBefore !== null) {
            /** @var Attendance $record */
            $record = $this->getRecord();
            AttendanceCorrection::recordAdminChange($record->fresh() ?? $record, $this->auditBefore, auth()->user(), 'Diubah admin di halaman Data Absensi.');
            $this->auditBefore = null;
        }
    }

    /**
     * Whether the form sets another status or another number of late minutes than the row has.
     *
     * @param  array<string, mixed>  $data
     */
    private function verdictChanged(array $data): bool
    {
        $record = $this->getRecord();

        if (array_key_exists('status', $data)) {
            $status = $data['status'] instanceof AttendanceStatus ? $data['status'] : AttendanceStatus::tryFrom((string) $data['status']);

            if ($status !== $record->status) {
                return true;
            }
        }

        return array_key_exists('late_minutes', $data) && (int) $data['late_minutes'] !== (int) $record->late_minutes;
    }

    /**
     * The time to the minute, as the form drops seconds, or null when empty.
     */
    private function minuteOf(CarbonInterface|string|null $time): ?string
    {
        return filled($time) ? Carbon::parse($time)->format('Y-m-d H:i') : null;
    }
}
