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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reviewer probes for the roster live state (group roster-mobile).
 */
class Review_roster_mobileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $brand;

    private CompanyLocation $outlet;

    private Shift $night;

    private Shift $afternoon;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Role::findOrCreate('Koordinator', 'web');

        $this->company = Company::create(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $this->brand = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Outlet A', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->night = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Malam', 'code' => 'M', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->afternoon = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'name' => 'Siang', 'code' => 'S', 'start_time' => '14:00', 'end_time' => '22:00']);

        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->brand->id]);
        Sanctum::actingAs($coordinator);
    }

    /** Night 22:00-06:00 on Monday, Tuesday a day off: at 02:00 Tuesday Eko is still on the night shift. */
    public function test_night_worker_with_day_off_today_is_still_shown_working(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 02:00'));
        $eko = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'full_name' => 'Eko', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $eko->id, 'date' => '2026-11-16', 'shift_id' => $this->night->id, 'company_location_id' => $this->outlet->id]);
        ShiftSchedule::create(['freelancer_id' => $eko->id, 'date' => '2026-11-17', 'is_day_off' => true]);
        Attendance::create(['freelancer_id' => $eko->id, 'date' => '2026-11-16', 'segment' => 1, 'check_in' => '2026-11-16 21:55', 'status' => 'on_time', 'shift_id' => $this->night->id, 'company_location_id' => $this->outlet->id]);

        $entries = collect($this->getJson('/api/v1/team/roster')->assertOk()->json('outlets'))->flatMap(fn (array $o) => $o['entries']);
        $working = $entries->first(fn (array $e): bool => $e['worker']['name'] === 'Eko' && $e['check_in'] === '21:55');

        $this->assertNotNull($working, 'Eko is on last night\'s shift at 02:00 but today\'s view only shows "Libur": '.json_encode($entries->all()));
    }

    /**
     * Night shift Monday checked in 22:00, check-out forgotten. Tuesday Eko is scheduled 14:00-22:00.
     * At 15:00 Tuesday he has not checked in to today's shift (the check service refuses a new check-in
     * while the overnight row is open), yet the roster shows him "hadir" and counts him as checked in.
     */
    public function test_forgotten_overnight_row_masks_a_missed_shift_today(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 15:00'));
        $eko = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->brand->id, 'full_name' => 'Eko', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $eko->id, 'date' => '2026-11-16', 'shift_id' => $this->night->id, 'company_location_id' => $this->outlet->id]);
        ShiftSchedule::create(['freelancer_id' => $eko->id, 'date' => '2026-11-17', 'shift_id' => $this->afternoon->id, 'company_location_id' => $this->outlet->id]);
        Attendance::create(['freelancer_id' => $eko->id, 'date' => '2026-11-16', 'segment' => 1, 'check_in' => '2026-11-16 22:00', 'status' => 'on_time', 'shift_id' => $this->night->id, 'company_location_id' => $this->outlet->id]);

        $outlet = $this->getJson('/api/v1/team/roster')->assertOk()->json('outlets.0');

        $this->assertSame(0, $outlet['counts']['checked_in'], 'Today\'s 14:00 shift has no check-in, but the header counts Eko as checked in.');
        $this->assertSame(1, $outlet['counts']['belum'], 'Eko missed today\'s 14:00 shift; teamAttention never flags him.');
    }
}
