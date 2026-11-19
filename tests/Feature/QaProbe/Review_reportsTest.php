<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reviewer probes for the "reports" group (RPT-B5/B7/B8).
 */
class Review_reportsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $brand;

    private CompanyLocation $outlet;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Review', 'code' => 'RVW', 'is_active' => true]);
        $this->company->policy()->update(['work_start_time' => '09:00:00', 'work_end_time' => '17:00:00']);
        $this->brand = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Outlet', 'latitude' => -6.2, 'longitude' => 106.7]);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->brand->id]);
        Sanctum::actingAs($this->coordinator);
    }

    /**
     * Rekap Tim keeps a rejected check-in on a scheduled day off as Libur (not absent); the API must agree.
     */
    public function test_api_does_not_count_a_rejected_check_in_on_a_scheduled_day_off_as_absent(): void
    {
        $worker = $this->freelancer('Ditolak Saat Libur');
        // On the roster since October, so the November weekdays read the roster only.
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-10-26', 'is_day_off' => true]);
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'is_day_off' => true]);
        Attendance::create(['freelancer_id' => $worker->id, 'date' => '2026-11-06', 'check_in' => '2026-11-06 08:00:00', 'status' => 'rejected', 'late_minutes' => 0]);

        $row = collect(app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01')))->firstWhere('worker.id', $worker->id);
        $this->assertSame(0, $row['absent_days']);

        $summary = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-06&end_date=2026-11-06')->assertOk()->json('summary');
        $this->assertSame(0, $summary['total_absent'], 'API counts a rejected check-in on a day off as absent; Rekap Tim says Libur.');
    }

    /**
     * Deleting a 10h shift used in a closed month silently rewrites its Jam Terjadwal to the policy 8h.
     */
    public function test_deleting_a_shift_keeps_its_own_scheduled_hours_in_a_closed_month(): void
    {
        $long = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Panjang', 'start_time' => '07:00', 'end_time' => '17:00']);
        $worker = $this->freelancer('Shift Dihapus');
        foreach (['2026-11-02', '2026-11-03'] as $date) {
            ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $long->id, 'company_location_id' => $this->outlet->id]);
        }

        $long->delete();

        $row = collect(app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01')))->firstWhere('worker.id', $worker->id);
        $this->assertSame(20.0, $row['scheduled_hours']);
    }

    private function freelancer(string $name): Freelancer
    {
        return Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'full_name' => $name, 'status' => 'active']);
    }
}
