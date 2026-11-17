<?php

namespace Tests\Feature;

use App\Exports\AttendanceRecapSheet;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real-device QA (17 Nov 2026): a freelancer's 2-hour overtime was approved by the PIC on the phone,
 * yet it appeared in neither Rekap Tim nor its Excel, the reports used to pay freelancers. Approved
 * overtime is now counted (the actual duration once filled in), pending and rejected overtime is not.
 */
class OvertimePayrollRecapTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-17';

    private Company $company;

    private Division $division;

    private Shift $shift;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.onesignal.app_id' => null,
            'services.onesignal.rest_api_key' => null,
        ]);
        Http::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse('2026-11-18 09:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien QA', 'code' => 'KQ', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Outlet QA', 'code' => 'QAFL', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'code' => 'OUT', 'is_active' => true]);
        $this->shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Dini', 'start_time' => '01:30', 'end_time' => '10:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);

        $this->pic = User::factory()->create(['is_active' => true, 'data_scope' => 'all']);
        $this->pic->forceFill(['tenant_id' => $tenant->id])->save();
        $this->pic->assignRole(['Admin', 'PIC']);
    }

    private function freelancer(string $name): Freelancer
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Freelancer');

        $freelancer = Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'freelancer_number' => 'FL-'.fake()->unique()->numberBetween(1000, 9999),
            'full_name' => $name,
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => self::DAY, 'shift_id' => $this->shift->id]);
        Attendance::create([
            'freelancer_id' => $freelancer->id,
            'date' => self::DAY,
            'shift_id' => $this->shift->id,
            'check_in' => self::DAY.' 01:30:00',
            'check_out' => self::DAY.' 10:00:00',
            'status' => 'on_time',
            'late_minutes' => 0,
        ]);

        return $freelancer;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function overtime(Freelancer $freelancer, string $status, int $minutes, array $attributes = []): OvertimeRequest
    {
        return OvertimeRequest::create([
            'freelancer_id' => $freelancer->id,
            'date' => self::DAY,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'duration_minutes' => $minutes,
            'reason' => 'Stok opname outlet',
            'status' => $status,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function recapRow(Freelancer $freelancer): array
    {
        return collect(app(TeamRecapService::class)->recap($this->pic, Carbon::parse(self::DAY)))
            ->firstWhere('worker.id', $freelancer->id);
    }

    public function test_team_recap_counts_only_approved_overtime(): void
    {
        $andi = $this->freelancer('Andi');
        $this->overtime($andi, 'approved', 120);
        $this->overtime($andi, 'pending', 60);
        $this->overtime($andi, 'rejected', 180);

        $row = $this->recapRow($andi);

        $this->assertSame(1, $row['overtime_count']);
        $this->assertSame(2.0, $row['overtime_hours']);
        $this->assertSame(1, $row['present_days'], 'Overtime on a worked day does not add a day.');
    }

    public function test_actual_duration_replaces_the_requested_one(): void
    {
        $andi = $this->freelancer('Andi');
        $this->overtime($andi, 'approved', 120, ['actual_duration_minutes' => 90]);

        $this->assertSame(1.5, $this->recapRow($andi)['overtime_hours']);
    }

    public function test_overtime_outside_the_month_is_not_counted(): void
    {
        $andi = $this->freelancer('Andi');
        $this->overtime($andi, 'approved', 120, ['date' => '2026-10-31']);
        $this->overtime($andi, 'approved', 120, ['date' => '2026-12-01']);

        $this->assertSame(0, $this->recapRow($andi)['overtime_count']);
    }

    public function test_team_recap_excel_has_overtime_columns(): void
    {
        $andi = $this->freelancer('Andi');
        $this->overtime($andi, 'approved', 120);

        $sheet = IOFactory::load(app(TeamRecapService::class)->export($this->pic, Carbon::parse(self::DAY)))->getActiveSheet()->toArray();
        $headings = $sheet[0];
        $data = collect($sheet)->first(fn (array $line): bool => $line[4] === 'Andi');
        $total = collect($sheet)->first(fn (array $line): bool => $line[0] === 'Total');

        $this->assertSame(1, (int) $data[array_search('Lembur (kali)', $headings, true)]);
        $this->assertSame(2.0, (float) $data[array_search('Total Lembur (jam)', $headings, true)]);
        $this->assertSame(2.0, (float) $total[array_search('Total Lembur (jam)', $headings, true)]);
    }

    public function test_mobile_team_recap_api_returns_overtime(): void
    {
        $andi = $this->freelancer('Andi');
        $this->overtime($andi, 'approved', 120);
        Sanctum::actingAs($this->pic);

        $row = collect($this->getJson('/api/v1/team/recap?month=2026-11')->assertOk()->json('data'))
            ->firstWhere('worker.id', $andi->id);

        $this->assertSame(1, $row['overtime_count']);
        $this->assertEquals(2, $row['overtime_hours']);
    }

    public function test_admin_recap_sheet_shows_overtime_and_early_leave_per_worker(): void
    {
        $andi = $this->freelancer('Andi');
        $budi = $this->freelancer('Budi');
        $this->overtime($andi, 'approved', 150);
        $this->overtime($budi, 'rejected', 120);
        Attendance::where('freelancer_id', $budi->id)->sole()->update(['check_out' => self::DAY.' 09:00:00']);

        $rows = (new AttendanceRecapSheet(Attendance::with(['employee.company', 'employee.division', 'intern.company', 'intern.division', 'freelancer.company', 'freelancer.division'])->get(), Carbon::parse(self::DAY), Carbon::parse(self::DAY)))->array();
        $headings = $rows[1];
        $overtimeColumn = array_search('Lembur (jam)', $headings, true);
        $earlyColumn = array_search('Pulang Cepat (menit)', $headings, true);
        $byName = collect(array_slice($rows, 2))->keyBy(0);

        $this->assertEqualsWithDelta(2.5, $byName['Andi'][$overtimeColumn], 0.001);
        $this->assertEqualsWithDelta(0.0, $byName['Budi'][$overtimeColumn], 0.001);
        $this->assertSame(0, $byName['Andi'][$earlyColumn]);
        $this->assertSame(60, $byName['Budi'][$earlyColumn]);
    }
}
