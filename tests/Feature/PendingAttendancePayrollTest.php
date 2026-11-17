<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Exports\AttendanceRecapSheet;
use App\Filament\Pages\AbsenHariIni;
use App\Filament\Pages\LaporanAbsensi;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\TeamRecapService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A check-in outside the outlet waits for approval with late_minutes 0, so in the pay reports a
 * 40-minute-late arrival read as on time until (or unless) someone approved it, and a rejected
 * check-in showed a green "Tepat Waktu" badge on the web. Reports now use the lateness judged at
 * check-in for pending rows and count the days still waiting for approval.
 */
class PendingAttendancePayrollTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-16';

    private Company $company;

    private Division $division;

    private Shift $morning;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Role::findOrCreate('Koordinator', 'web');
        Notification::fake();
        Queue::fake();
        Http::fake();

        $this->travelTo(Carbon::parse(self::DAY.' 07:00:00'));

        $this->company = Company::create(['name' => 'PT Outlet', 'code' => 'OUTL', 'latitude' => -6.1753924, 'longitude' => 106.8271528, 'is_active' => true]);
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);
        $this->company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode('none'),
            'require_gps' => true,
            'geofence_radius_meters' => 100,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 15]);

        $this->coordinator = User::factory()->create();
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->division->id]);
    }

    private function freelancer(string $name): Freelancer
    {
        $user = User::factory()->create();
        $user->assignRole('Freelancer');

        $worker = Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => $name,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
        ]);
        ShiftSchedule::create(['date' => self::DAY, 'freelancer_id' => $worker->id, 'shift_id' => $this->morning->id]);

        return $worker;
    }

    /** Checks in at 08:40 (40 minutes late) far outside the outlet, so the check-in waits for approval. */
    private function lateCheckInOutsideTheOutlet(Freelancer $worker): Attendance
    {
        $this->travelTo(Carbon::parse(self::DAY.' 08:40:00'));
        Sanctum::actingAs(User::findOrFail($worker->user_id));
        $this->postJson('/api/v1/attendance/check-in', ['latitude' => -6.3, 'longitude' => 106.9])->assertOk();

        return Attendance::where('freelancer_id', $worker->id)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function recapRow(Freelancer $worker): array
    {
        return collect(app(TeamRecapService::class)->recap($this->coordinator, Carbon::parse(self::DAY)))
            ->firstWhere('worker.id', $worker->id);
    }

    public function test_pending_late_check_in_is_late_in_team_recap_and_counted_as_pending(): void
    {
        $worker = $this->freelancer('Andi');
        $row = $this->lateCheckInOutsideTheOutlet($worker);

        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertSame(0, (int) $row->late_minutes);
        $this->assertSame(40, $row->reportedLateMinutes());

        $recap = $this->recapRow($worker);
        $this->assertSame([1, 1, 1, 40], [$recap['present_days'], $recap['pending_days'], $recap['late_days'], $recap['late_minutes']]);
    }

    public function test_after_approval_the_day_is_no_longer_pending_and_stays_late(): void
    {
        $worker = $this->freelancer('Andi');
        $row = $this->lateCheckInOutsideTheOutlet($worker);
        $admin = User::factory()->create();
        $admin->assignRole('Superadmin');

        app(ApprovalFlowService::class)->approveStep($row->fresh(), $admin);

        $this->assertSame(AttendanceStatus::Late, $row->fresh()->status);
        $recap = $this->recapRow($worker);
        $this->assertSame([1, 0, 1, 40], [$recap['present_days'], $recap['pending_days'], $recap['late_days'], $recap['late_minutes']]);
    }

    public function test_on_time_pending_check_in_is_pending_but_not_late(): void
    {
        $worker = $this->freelancer('Budi');
        $this->travelTo(Carbon::parse(self::DAY.' 07:55:00'));
        Sanctum::actingAs(User::findOrFail($worker->user_id));
        $this->postJson('/api/v1/attendance/check-in', ['latitude' => -6.3, 'longitude' => 106.9])->assertOk();

        $recap = $this->recapRow($worker);
        $this->assertSame([1, 0, 0], [$recap['pending_days'], $recap['late_days'], $recap['late_minutes']]);
    }

    public function test_team_recap_excel_has_a_pending_column(): void
    {
        $worker = $this->freelancer('Andi');
        $this->lateCheckInOutsideTheOutlet($worker);

        $sheet = IOFactory::load(app(TeamRecapService::class)->export($this->coordinator, Carbon::parse(self::DAY)))->getActiveSheet()->toArray();
        $headings = $sheet[0];
        $data = collect($sheet)->first(fn (array $line): bool => $line[4] === 'Andi');

        $this->assertSame(1, (int) $data[array_search('Menunggu Persetujuan (hari)', $headings, true)]);
        $this->assertSame(40, (int) $data[array_search('Total Telat (menit)', $headings, true)]);
    }

    public function test_api_report_summary_counts_a_pending_late_day_as_late(): void
    {
        $worker = $this->freelancer('Andi');
        $this->lateCheckInOutsideTheOutlet($worker);
        Sanctum::actingAs($this->coordinator);

        $summary = $this->getJson('/api/v1/laporan-absensi?start_date='.self::DAY.'&end_date='.self::DAY)->assertOk()->json('summary');

        $this->assertSame(1, $summary['total_present']);
        $this->assertSame(1, $summary['total_late']);
    }

    public function test_admin_recap_sheet_and_status_label_show_the_pending_lateness(): void
    {
        $worker = $this->freelancer('Andi');
        $row = $this->lateCheckInOutsideTheOutlet($worker);

        $rows = (new AttendanceRecapSheet(Attendance::with(['employee.company', 'employee.division', 'intern.company', 'intern.division', 'freelancer.company', 'freelancer.division'])->get(), Carbon::parse(self::DAY), Carbon::parse(self::DAY)))->array();
        $lateColumn = array_search('Telat (menit)', $rows[1], true);

        $this->assertSame(40, $rows[2][$lateColumn]);
        $this->assertSame('Menunggu Persetujuan (telat 40m)', app(LaporanAbsensi::class)->statusLabel($row->fresh()));
    }

    public function test_absen_hari_ini_shows_a_rejected_check_in_as_rejected_not_on_time(): void
    {
        $worker = $this->freelancer('Andi');
        $this->travelTo(Carbon::parse(self::DAY.' 08:00:00'));
        Attendance::create([
            'freelancer_id' => $worker->id,
            'date' => self::DAY,
            'check_in' => self::DAY.' 07:58:00',
            'status' => AttendanceStatus::Rejected,
            'late_minutes' => 0,
        ]);

        $this->actingAs(User::findOrFail($worker->user_id));

        Livewire::test(AbsenHariIni::class)
            ->assertSee('Ditolak')
            ->assertDontSee('Tepat Waktu');
    }
}
