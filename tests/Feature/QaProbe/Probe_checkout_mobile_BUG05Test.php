<?php

namespace Tests\Feature\QaProbe;

use App\Exports\AttendanceRecapSheet;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUG-05: an open attendance (check-in, no check-out) in the payroll recap export.
 */
class Probe_checkout_mobile_BUG05Test extends TestCase
{
    use RefreshDatabase;

    public function test_open_attendance_in_recap_export(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 10:00:00'));

        $company = Company::create(['name' => 'PT B5', 'code' => 'B5', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Outlet', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $freelancer = Freelancer::create([
            'user_id' => $user->id, 'company_id' => $company->id,
            'division_id' => $division->id, 'full_name' => 'Fre Open', 'status' => 'active',
        ]);

        Attendance::create([
            'freelancer_id' => $freelancer->id, 'date' => '2026-11-16', 'segment' => 1,
            'check_in' => '2026-11-16 08:00:00', 'check_out' => null,
            'status' => 'on_time', 'late_minutes' => 0,
        ]);

        $rows = (new AttendanceRecapSheet(Attendance::with('freelancer')->get(), Carbon::parse('2026-11-16'), Carbon::parse('2026-11-16')))->array();
        $row = $rows[2];
        fwrite(STDERR, "\nBUG05 headings=".json_encode(array_merge($rows[0], $rows[1]))."\nBUG05 row=".json_encode($row)."\n");

        // Columns: 0-4 identity, 5 Hari Masuk, 6 Terlambat, 7 Telat, 8 Pulang Cepat, 9 Lembur, 10 Jumlah Jam,
        // 11 Masuk, 12 Pulang, 13 Jam. Updated after the recap gained the Pulang Cepat and Lembur totals:
        // the export behaviour is unchanged, only the column positions moved by two.
        $this->assertSame(1, $row[5], 'Hari Masuk counts the open day (the worker did show up).');
        $this->assertEquals(0, $row[10], 'Jumlah Jam excludes the open day: no hours are paid for it.');
        $this->assertSame('08:00', $row[11]);
        $this->assertSame('-', $row[12], 'Pulang shows "-" so the missing check-out is visible to the PIC.');
        $this->assertNull($row[13], 'Jam for the day is blank, not a fabricated number.');
        $this->assertNotContains('Tepat Waktu', $row, 'The recap export does not print a status label for a checked-in day.');

        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");
        fwrite(STDERR, "BUG05 scheduled=\n{$scheduled}\n");
        $this->assertStringNotContainsString('close-open', $scheduled);
    }
}
