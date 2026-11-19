<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-SCH-4: a scheduled shift covered by an approved leave must not be counted as absent in Rekap Tim.
 */
class Probe_scheduling_BUGSCH4Test extends TestCase
{
    use RefreshDatabase;

    public function test_approved_leave_on_a_scheduled_day_is_not_absent_in_team_recap(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 12:00'));
        Role::findOrCreate('Koordinator', 'web');

        $company = Company::create(['name' => 'PT SCH4', 'code' => 'SCH4', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $pagi = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $worker = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Freelance Cuti', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-17', 'shift_id' => $pagi->id, 'company_location_id' => $outlet->id, 'is_day_off' => false]);

        $leave = LeaveRequest::create([
            'freelancer_id' => $worker->id, 'leave_type' => 'permission', 'start_date' => '2026-11-17', 'end_date' => '2026-11-17',
            'days_count' => 1, 'reason' => 'Urusan keluarga', 'status' => 'approved',
        ]);
        $leave->applyLeave();

        $row = Attendance::query()->where('freelancer_id', $worker->id)->firstOrFail();
        dump(['attendance' => [$row->date->toDateString(), $row->status->value, $row->check_in]]);

        $rows = app(TeamRecapService::class)->recap($coordinator, Carbon::parse('2026-11-01'));
        $mine = collect($rows)->firstWhere('worker.id', $worker->id);
        dump(array_intersect_key($mine, array_flip(['outlet', 'scheduled_days', 'present_days', 'absent_days', 'off_days'])));

        $this->assertSame(0, $mine['present_days']);
        $this->assertSame(1, $mine['leave_days'], 'The approved leave day is a leave day.');
        // The recap now counts the no-shows laporan absensi always counted: the eleven weekdays (2 - 16 Nov) before
        // the date of this worker's first roster row, when the company week (Monday to Friday) was expected of them.
        // The leave day itself (the 17th) is not among them.
        $this->assertSame(11, $mine['absent_days'], 'An approved leave day must not be counted as Tidak Hadir (no-show).');
    }
}
