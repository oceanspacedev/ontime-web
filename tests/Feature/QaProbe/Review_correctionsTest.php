<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Reviewer probes for the corrections group: corrected times are never checked against each other
 * (or against the attendance's own other time), so a typo produces impossible attendance rows.
 */
class Review_correctionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::fake();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        Role::create(['name' => 'Employee']);
        Role::create(['name' => 'Approver']);

        $this->company = Company::create(['name' => 'PT Review', 'code' => 'RVW', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);
        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'correction', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
    }

    /**
     * Day shift 08:00-17:00: swapped times (in 17:00, out 08:00) are silently read as an overnight
     * stay and accepted, producing a 15-hour working day once approved.
     */
    public function test_swapped_times_on_a_day_shift_are_refused(): void
    {
        $worker = $this->makeWorker($this->shift('08:00', '17:00'));
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '17:00', 'corrected_check_out' => '08:00', 'reason' => 'Tertukar']);

        if ($response->status() === 201) {
            $correction = AttendanceCorrection::findOrFail($response->json('data.id'));
            $this->approve($correction);
            $attendance = Attendance::where('employee_id', $worker->employee->id)->sole();
            fwrite(STDERR, "\nREVIEW swapped: in={$attendance->check_in} out={$attendance->check_out} worked={$attendance->workedMinutes()} late={$attendance->late_minutes}\n");
        }

        $response->assertUnprocessable();
    }

    /**
     * A check-in-only correction later than the attendance's existing check-out is accepted and
     * applied, leaving check_in after check_out.
     */
    public function test_check_in_correction_after_the_existing_check_out_is_refused(): void
    {
        $shift = $this->shift('08:00', '17:00');
        $worker = $this->makeWorker($shift);
        $attendance = new Attendance;
        $attendance->forceFill(['employee_id' => $worker->employee->id, 'date' => '2026-11-15', 'segment' => 1, 'shift_id' => $shift->id, 'check_in' => '2026-11-15 08:05:00', 'check_out' => '2026-11-15 17:00:00', 'status' => 'on_time', 'late_minutes' => 0])->save();
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-15', 'corrected_check_in' => '18:00', 'reason' => 'Salah ketik']);

        if ($response->status() === 201) {
            $this->approve(AttendanceCorrection::findOrFail($response->json('data.id')));
            $attendance->refresh();
            fwrite(STDERR, "\nREVIEW in-after-out: in={$attendance->check_in} out={$attendance->check_out} worked={$attendance->workedMinutes()} late={$attendance->late_minutes} status={$attendance->status->value}\n");
        }

        $response->assertUnprocessable();
    }

    private function approve(AttendanceCorrection $correction): void
    {
        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $approver->grantDataAccess(DataScope::All);
        app(ApprovalFlowService::class)->approveStep($correction->fresh(), $approver);
    }

    private function shift(string $start, string $end): Shift
    {
        return Shift::create(['company_id' => $this->company->id, 'name' => "Shift {$start}", 'code' => 'R'.str_replace(':', '', $start), 'start_time' => $start, 'end_time' => $end, 'late_tolerance_minutes' => 10]);
    }

    private function makeWorker(Shift $shift): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'RVW-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
            'shift_id' => $shift->id,
        ]);

        return $user->fresh();
    }
}
