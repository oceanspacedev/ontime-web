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
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamRecapTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Freelancer $bayu;

    private Freelancer $outsider;

    private CompanyLocation $jchicken;

    private CompanyLocation $luuca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $jchickenBrand = Division::create(['company_id' => $company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $luucaBrand = Division::create(['company_id' => $company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $momoyoBrand = Division::create(['company_id' => $company->id, 'name' => 'MOMOYO', 'is_active' => true]);
        $this->jchicken = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $jchickenBrand->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->luuca = CompanyLocation::create(['company_id' => $company->id, 'division_id' => $luucaBrand->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $morning = Shift::create(['company_id' => $company->id, 'division_id' => $jchickenBrand->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);
        $closing = Shift::create(['company_id' => $company->id, 'division_id' => $luucaBrand->id, 'name' => 'Sore Closing', 'start_time' => '18:00', 'end_time' => '22:30']);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$jchickenBrand->id, $luucaBrand->id]);

        $this->bayu = Freelancer::create(['company_id' => $company->id, 'division_id' => $jchickenBrand->id, 'full_name' => 'Bayu Adillah', 'status' => 'active']);
        $this->outsider = Freelancer::create(['company_id' => $company->id, 'division_id' => $momoyoBrand->id, 'full_name' => 'Orang Momoyo', 'status' => 'active']);

        // Bayu at JChicken: worked late on the 2nd, missed the 3rd, off on the 4th, scheduled later on the 20th.
        $this->schedule('2026-11-02', $morning, $this->jchicken);
        $this->schedule('2026-11-03', $morning, $this->jchicken);
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-04', 'is_day_off' => true]);
        $this->schedule('2026-11-20', $morning, $this->jchicken);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:20:00', 'check_out' => '2026-11-02 17:05:00', 'status' => 'late', 'late_minutes' => 20, 'company_location_id' => $this->jchicken->id]);

        // Bayu helped at Luuca on the 5th.
        $this->schedule('2026-11-05', $closing, $this->luuca);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-11-05', 'check_in' => '2026-11-05 17:55:00', 'check_out' => '2026-11-05 22:25:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->luuca->id]);

        // Out of the coordinator's brands, and outside the month.
        Attendance::create(['freelancer_id' => $this->outsider->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);
        Attendance::create(['freelancer_id' => $this->bayu->id, 'date' => '2026-10-31', 'check_in' => '2026-10-31 08:00:00', 'check_out' => '2026-10-31 17:00:00', 'status' => 'on_time']);
    }

    public function test_recap_counts_each_person_per_outlet(): void
    {
        $rows = app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse('2026-11-01'));

        $this->assertCount(3, $rows);

        [$jchicken, $luuca, $noOutlet] = $rows;
        $this->assertSame(['JChicken Ciledug', 'Bayu Adillah'], [$jchicken['outlet'], $jchicken['worker']['name']]);
        $this->assertSame(
            ['scheduled_days' => 3, 'present_days' => 1, 'late_days' => 1, 'late_minutes' => 20, 'absent_days' => 1, 'off_days' => 0, 'scheduled_hours' => 27.0, 'worked_hours' => 8.75],
            array_intersect_key($jchicken, array_flip(['scheduled_days', 'present_days', 'late_days', 'late_minutes', 'absent_days', 'off_days', 'scheduled_hours', 'worked_hours'])),
            'The 20th is still to come, so it is scheduled but not absent.',
        );
        $this->assertSame(['Luuca Ciledug', 1, 1, 4.5, 4.5], [$luuca['outlet'], $luuca['scheduled_days'], $luuca['present_days'], $luuca['scheduled_hours'], $luuca['worked_hours']]);
        $this->assertSame([null, 1, 0], [$noOutlet['outlet'], $noOutlet['off_days'], $noOutlet['scheduled_days']], 'A day off has no outlet.');
        $this->assertNotContains('Orang Momoyo', array_column(array_column($rows, 'worker'), 'name'));
    }

    public function test_excel_holds_a_row_per_person_per_outlet_and_a_total(): void
    {
        $path = app(TeamRecapService::class)->export($this->coordinator, Carbon::parse('2026-11-01'));
        $rows = $this->readSheet($path);
        @unlink($path);

        $this->assertSame(['Badan Usaha', 'Divisi / Brand', 'Outlet', 'ID Pekerja', 'Nama', 'Jenis', 'Hari Terjadwal', 'Hari Masuk', 'Tidak Hadir', 'Libur', 'Terlambat (kali)', 'Total Telat (menit)', 'Jam Terjadwal', 'Jam Kerja'], $rows[0]);
        $this->assertSame(['PT Franchise', 'JCHICKEN', 'JChicken Ciledug', $this->bayu->freelancer_number, 'Bayu Adillah', 'Freelancer', '3', '1', '1', '0', '1', '20', '27', '8.75'], $rows[1]);
        $this->assertSame(['Total', '3 baris', '4', '2', '1', '1', '1', '20', '31.5', '13.25'], [$rows[4][0], $rows[4][4], ...array_slice($rows[4], 6)]);
    }

    public function test_app_gets_the_recap_and_a_short_lived_download_link(): void
    {
        Sanctum::actingAs($this->coordinator);

        $this->getJson('/api/v1/team/recap?month=2026-11')
            ->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonPath('data.0.present_days', 1)
            ->assertJsonCount(3, 'data');

        $this->getJson('/api/v1/team/recap?month=11-2026')->assertUnprocessable();

        $url = $this->getJson('/api/v1/team/recap/link?month=2026-11')->assertOk()->json('url');

        $this->get($url)->assertOk()->assertDownload('rekap-tim-2026-11.xlsx');
        $this->get(str_replace('month=2026-11', 'month=2026-10', $url))->assertForbidden();

        $this->travel(16)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_workers_cannot_get_a_recap(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('Freelancer');
        Sanctum::actingAs($worker);

        $this->getJson('/api/v1/team/recap')->assertForbidden();
        $this->getJson('/api/v1/team/recap/link')->assertForbidden();
    }

    public function test_panel_downloads_the_recap(): void
    {
        $this->actingAs($this->coordinator);

        Livewire::test(ListAttendances::class)
            ->callAction('teamRecap', data: ['month' => '2026-11'])
            ->assertHasNoFormErrors()
            ->assertFileDownloaded('rekap-tim-2026-11.xlsx');
    }

    private function schedule(string $date, Shift $shift, CompanyLocation $outlet): void
    {
        ShiftSchedule::create(['freelancer_id' => $this->bayu->id, 'date' => $date, 'shift_id' => $shift->id, 'company_location_id' => $outlet->id]);
    }

    /**
     * @return list<list<string>>
     */
    private function readSheet(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn (mixed $value): string => (string) $value, $row->toArray());
            }

            break;
        }

        $reader->close();

        return $rows;
    }
}
