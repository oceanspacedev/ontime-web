<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
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
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Standalone report defects fixed before the attendance engine changes: the recap month asked for on the
 * 29th-31st, a one-day range of the attendance list, and a pending row that is already checked out on the
 * roster. (The shift-swap snapshot comparison is covered in ShiftSwapRequestTest, next to its helpers.)
 */
class ReportingQuickFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Freelancer $bayu;

    private CompanyLocation $outlet;

    private Shift $morning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $brand = Division::create(['company_id' => $company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->morning = Shift::create(['company_id' => $company->id, 'division_id' => $brand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$brand->id]);

        $this->bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $brand->id, 'full_name' => 'Bayu Adillah', 'status' => 'active']);

        // November: worked the 2nd and the 3rd. February 2027: scheduled on the 1st.
        foreach (['2026-11-02', '2026-11-03', '2027-02-01'] as $date) {
            ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $date, 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        }

        $this->attend('2026-11-02');
        $this->attend('2026-11-03');
    }

    public function test_a_month_is_parsed_without_overflowing_on_the_last_days_of_a_longer_month(): void
    {
        foreach (['2026-10-31 10:00' => '2026-11', '2026-12-31 10:00' => '2026-11', '2027-01-31 10:00' => '2027-02', '2027-01-29 23:59' => '2027-02'] as $now => $month) {
            $this->travelTo(Carbon::parse($now));

            $parsed = TeamRecapService::parseMonth($month);

            $this->assertSame("{$month}-01 00:00:00", $parsed->toDateTimeString(), "Asked for {$month} on {$now}.");
        }
    }

    public function test_app_recap_of_a_shorter_month_asked_on_the_31st_is_that_month(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->travelTo(Carbon::parse('2026-10-31 10:00'));
        $this->getJson('/api/v1/team/recap?month=2026-11')
            ->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.scheduled_days', 2);

        $this->travelTo(Carbon::parse('2026-12-31 10:00'));
        $this->getJson('/api/v1/team/recap?month=2026-11')
            ->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonPath('data.0.present_days', 2);

        $this->travelTo(Carbon::parse('2027-01-31 10:00'));
        $this->getJson('/api/v1/team/recap?month=2027-02')
            ->assertOk()
            ->assertJsonPath('month', '2027-02')
            ->assertJsonPath('data.0.scheduled_days', 1);
    }

    public function test_download_link_of_a_shorter_month_asked_on_the_31st_is_that_month(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->travelTo(Carbon::parse('2026-10-31 10:00'));
        $url = $this->getJson('/api/v1/team/recap/link?month=2026-11')->assertOk()->json('url');
        $this->assertStringContainsString('month=2026-11', $url);
        $this->get($url)->assertOk()->assertDownload('rekap-tim-2026-11.xlsx');

        $this->travelTo(Carbon::parse('2027-01-31 10:00'));
        $url = $this->getJson('/api/v1/team/recap/link?month=2027-02')->assertOk()->json('url');
        $this->assertStringContainsString('month=2027-02', $url);
        $this->get($url)->assertOk()->assertDownload('rekap-tim-2027-02.xlsx');
    }

    public function test_panel_recap_of_a_shorter_month_asked_on_the_31st_is_that_month(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 10:00'));
        $this->actingAs($this->coordinator);

        Livewire::test(ListAttendances::class)
            ->callAction('teamRecap', data: ['month' => '2026-11'])
            ->assertHasNoFormErrors()
            ->assertFileDownloaded('rekap-tim-2026-11.xlsx');
    }

    public function test_attendance_list_of_one_day_returns_that_day(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/v1/attendances?start_date=2026-11-02&end_date=2026-11-02')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.date', '2026-11-02');

        $this->getJson('/api/v1/attendances?start_date=2026-11-03&end_date=2026-11-03')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.date', '2026-11-03');

        $this->getJson('/api/v1/attendances?start_date=2026-11-02&end_date=2026-11-03')->assertOk()->assertJsonPath('pagination.total', 2);
        $this->getJson('/api/v1/attendances?start_date=2026-11-04&end_date=2026-11-30')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/v1/attendances?start_date=bukan-tanggal&end_date=2026-11-03')->assertOk()->assertJsonPath('pagination.total', 0);
    }

    public function test_a_pending_row_that_is_checked_out_still_waits_on_the_roster(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 18:00'));
        Sanctum::actingAs($this->coordinator);

        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-16', 'shift_id' => $this->morning->id, 'company_location_id' => $this->outlet->id]);
        $row = Attendance::create([
            'freelancer_id' => $this->bayu->id,
            'date' => '2026-11-16',
            'check_in' => '2026-11-16 08:40:00',
            'check_out' => '2026-11-16 17:00:00',
            'status' => 'pending_approval',
            'late_minutes' => 0,
            'check_in_late_minutes' => 40,
            'company_location_id' => $this->outlet->id,
        ]);

        $outlet = $this->getJson('/api/v1/team/roster?date=2026-11-16')->assertOk()->json('outlets.0');

        $this->assertSame('menunggu', $outlet['entries'][0]['state']);
        $this->assertSame([1, 0], [$outlet['counts']['menunggu'], $outlet['counts']['pulang']]);
        $this->assertSame('17:00', $outlet['entries'][0]['check_out']);
        $this->assertSame(40, $outlet['entries'][0]['late_minutes'], 'A pending row reports the lateness judged at check-in.');

        $row->update(['status' => 'late', 'late_minutes' => 40]);
        $approved = $this->getJson('/api/v1/team/roster?date=2026-11-16')->assertOk()->json('outlets.0.entries.0');
        $this->assertSame(['pulang', 40], [$approved['state'], $approved['late_minutes']]);

        $row->update(['status' => 'rejected', 'late_minutes' => 40]);
        $rejected = $this->getJson('/api/v1/team/roster?date=2026-11-16')->assertOk()->json('outlets.0.entries.0');
        $this->assertSame(['ditolak', 0], [$rejected['state'], $rejected['late_minutes']], 'A refused row is not a late arrival.');
    }

    private function attend(string $date): void
    {
        Attendance::create([
            'freelancer_id' => $this->bayu->id,
            'date' => $date,
            'check_in' => "{$date} 08:00:00",
            'check_out' => "{$date} 17:00:00",
            'status' => 'on_time',
            'late_minutes' => 0,
            'company_location_id' => $this->outlet->id,
        ]);
    }
}
