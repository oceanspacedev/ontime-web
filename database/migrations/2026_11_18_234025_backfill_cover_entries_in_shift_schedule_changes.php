<?php

use App\Enums\AttendanceStatus;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * The note the cover approval wrote on a worker's own roster row before the change log existed.
     */
    private const COVER_NOTE = 'Masuk di luar jadwal (disetujui)';

    /**
     * Give a cover's own roster row written before the change log existed its "created" entry with the
     * source "cover", so {@see ShiftSchedule::whereRosterRow()} leaves it out the same way it
     * leaves out a cover row written today: a cover alone never ends a worker's fixed hours.
     *
     * A legacy cover row carries the approval's note, has no "created" entry and is linked to an
     * off-schedule check-in that was not rejected. It was written by the approval, so it is not older
     * than that check-in. A PIC day-off row the approval converted carries the same note but was written
     * before the check-in, and keeps counting as a roster row. Runs again harmlessly: a row that already
     * has its "created" entry is skipped.
     *
     * Post-check after deploy, must be 0:
     * SELECT COUNT(*) FROM shift_schedules s WHERE s.notes = 'Masuk di luar jadwal (disetujui)'
     *   AND NOT EXISTS (SELECT 1 FROM shift_schedule_changes c WHERE c.shift_schedule_id = s.id AND c.action = 'created')
     *   AND EXISTS (SELECT 1 FROM attendances a WHERE a.shift_schedule_id = s.id AND a.is_off_schedule = 1
     *     AND a.check_in IS NOT NULL AND a.created_at <= s.created_at AND (a.status IS NULL OR a.status != 'rejected'))
     */
    public function up(): void
    {
        $written = 0;

        DB::table('shift_schedules')
            ->where('notes', self::COVER_NOTE)
            ->where('is_day_off', false)
            ->whereNotNull('shift_id')
            ->whereNotNull('created_at')
            ->whereNotExists(fn (Builder $created) => $created->selectRaw('1')
                ->from('shift_schedule_changes')
                ->whereColumn('shift_schedule_changes.shift_schedule_id', 'shift_schedules.id')
                ->where('shift_schedule_changes.action', ShiftScheduleChange::ACTION_CREATED))
            ->whereExists(fn (Builder $cover) => $cover->selectRaw('1')
                ->from('attendances')
                ->whereColumn('attendances.shift_schedule_id', 'shift_schedules.id')
                ->where('attendances.is_off_schedule', true)
                ->whereNotNull('attendances.check_in')
                ->whereColumn('attendances.created_at', '<=', 'shift_schedules.created_at')
                ->where(fn (Builder $notRejected) => $notRejected->whereNull('attendances.status')->orWhere('attendances.status', '!=', AttendanceStatus::Rejected->value)))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$written): void {
                $shiftCodes = DB::table('shifts')->whereIn('id', $rows->pluck('shift_id')->filter()->unique())->pluck('code', 'id');
                $outletNames = DB::table('company_locations')->whereIn('id', $rows->pluck('company_location_id')->filter()->unique())->pluck('name', 'id');

                $entries = $rows->map(fn (object $row): array => [
                    'shift_schedule_id' => $row->id,
                    'employee_id' => $row->employee_id,
                    'intern_id' => $row->intern_id,
                    'freelancer_id' => $row->freelancer_id,
                    'date' => substr((string) $row->date, 0, 10),
                    'sequence' => (int) ($row->sequence ?: 1),
                    'action' => ShiftScheduleChange::ACTION_CREATED,
                    'source' => 'cover',
                    'before' => null,
                    'after' => json_encode([
                        'is_day_off' => false,
                        'shift_id' => (int) $row->shift_id,
                        'shift_code' => ($shiftCodes[$row->shift_id] ?? null) ?: null,
                        'company_location_id' => $row->company_location_id ? (int) $row->company_location_id : null,
                        'outlet_name' => $row->company_location_id ? ($outletNames[$row->company_location_id] ?? null) : null,
                        'section' => $row->section ?? null,
                        'sequence' => (int) ($row->sequence ?: 1),
                    ]),
                    'reason' => 'Diisi dari catatan persetujuan "Masuk di luar jadwal" sebelum log perubahan jadwal ada',
                    'changed_by' => null,
                    'created_at' => $row->created_at,
                ])->all();

                DB::table('shift_schedule_changes')->insert($entries);
                $written += count($entries);
            });

        if (! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(sprintf('  Log perubahan jadwal: %d baris jadwal "Masuk di luar jadwal" lama ditandai sebagai milik persetujuan.', $written));
        }
    }

    /**
     * The entries are history like any other; there is nothing to undo here.
     */
    public function down(): void {}
};
