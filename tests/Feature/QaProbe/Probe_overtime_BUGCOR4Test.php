<?php

namespace Tests\Feature\QaProbe;

use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * BUG-COR-4: a correction for a date that has not happened yet must be refused,
 * and must never become an approved, paid "on time" attendance in the future.
 */
class Probe_overtime_BUGCOR4Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-16 10:00:00');

        Role::create(['name' => 'Employee']);
        Role::create(['name' => 'Approver']);

        $this->company = Company::create(['name' => 'PT Probe', 'code' => 'PRB', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);

        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'correction', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_api_rejects_a_correction_for_a_future_date(): void
    {
        Sanctum::actingAs($this->makeWorker());

        $response = $this->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-11-25',
            'corrected_check_in' => '08:00',
            'corrected_check_out' => '17:00',
            'reason' => 'Koreksi untuk tanggal yang belum terjadi',
        ]);

        $this->assertSame(422, $response->status(), 'Future-date correction was accepted with status '.$response->status());
        $this->assertSame(0, AttendanceCorrection::count());
    }

    public function test_applying_a_future_correction_does_not_create_a_future_attendance(): void
    {
        $worker = $this->makeWorker();
        Sanctum::actingAs($worker);

        $id = $this->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-11-25',
            'corrected_check_in' => '08:00',
            'corrected_check_out' => '17:00',
            'reason' => 'Koreksi untuk tanggal yang belum terjadi',
        ])->json('data.id');

        if ($id !== null) {
            try {
                AttendanceCorrection::findOrFail($id)->applyCorrection();
            } catch (\Throwable) {
                // Refusing at apply time is also acceptable.
            }
        }

        $future = Attendance::where('employee_id', $worker->employee->id)->whereDate('date', '2026-11-25')->first();
        $this->assertNull($future, sprintf(
            'Future attendance created on 2026-11-25: status=%s worked=%s',
            $future?->status?->value,
            $future?->workedMinutes(),
        ));
    }

    private function makeWorker(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'PRB-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
        ]);

        return $user->fresh();
    }
}
