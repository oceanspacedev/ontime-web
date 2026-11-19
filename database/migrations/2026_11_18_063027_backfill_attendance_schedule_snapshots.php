<?php

use App\Services\AttendanceSnapshotBackfill;
use Illuminate\Database\Migrations\Migration;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Fill the schedule snapshot of attendance checked in before the snapshot existed. Kept apart from
     * the schema change so it can be resumed: it only handles rows that have no snapshot yet. To resume
     * by hand, run app(AttendanceSnapshotBackfill::class)->run().
     *
     * Post-check after deploy, must be 0:
     * SELECT COUNT(*) FROM attendances WHERE check_in IS NOT NULL AND sched_source IS NULL
     */
    public function up(): void
    {
        $result = app(AttendanceSnapshotBackfill::class)->run();

        if (! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(sprintf(
                '  Snapshot jadwal absensi: %d baris diisi, %d ditautkan ke jadwal, %d baris check-in masih tanpa snapshot (harus 0).',
                $result['filled'],
                $result['linked'],
                $result['remaining'],
            ));
        }
    }

    /**
     * The snapshot columns are dropped by the schema migration; there is nothing to undo here.
     */
    public function down(): void {}
};
