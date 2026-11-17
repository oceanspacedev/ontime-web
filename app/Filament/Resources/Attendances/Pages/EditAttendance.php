<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\Attendances\AttendanceResource;
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
     * The columns a check-in, check-out, approval or another admin can change while this form is open.
     *
     * @var list<string>
     */
    private const WATCHED_COLUMNS = [
        'shift_id', 'check_in', 'check_out', 'status', 'late_minutes', 'early_leave_minutes',
        'notes', 'is_corrected', 'status_overridden',
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
            DeleteAction::make(),
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

        // Only the verdict was edited (for example a late arrival excused): it is the admin's call, which a
        // later roster change on that date must not overwrite.
        if (! $this->timesMoved && $this->verdictChanged($data)) {
            $data['status_overridden'] = true;
        }

        return $data;
    }

    /**
     * Moved times are judged again like a roster change: shift, lateness and status (or the snapshot of a
     * row waiting for approval) and early leave follow the new times instead of keeping the old verdict,
     * even one an admin set by hand before.
     */
    protected function afterSave(): void
    {
        if ($this->timesMoved) {
            $this->timesMoved = false;
            app(AttendanceLatenessService::class)->recalculate($this->getRecord(), force: true);
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
