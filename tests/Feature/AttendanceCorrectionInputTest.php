<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\AttendanceCorrections\Pages\CreateAttendanceCorrection;
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
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * QA-STAGING RSK-05/ATT-048 (night correction keeps the next-day check-out) and
 * RSK-06/ATT-047 (broken or empty corrected times are a 422, not a 500 or a no-op correction).
 */
class AttendanceCorrectionInputTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Employee']);
        Role::create(['name' => 'Approver']);

        $this->company = Company::create(['name' => 'PT Koreksi', 'code' => 'KRS', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);

        ApprovalFlow::create(['company_id' => $this->company->id, 'request_type' => 'correction', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Approver']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidTimes(): array
    {
        return [
            'teks bebas' => [['corrected_check_in' => 'abc', 'corrected_check_out' => '17:00'], 'corrected_check_in'],
            'jam di luar rentang' => [['corrected_check_in' => '25:61', 'corrected_check_out' => '17:00'], 'corrected_check_in'],
            'jam pulang rusak' => [['corrected_check_in' => '08:00', 'corrected_check_out' => '17:99'], 'corrected_check_out'],
            'kedua jam kosong' => [['corrected_check_in' => '', 'corrected_check_out' => ''], 'corrected_check_in'],
            'kedua jam tidak dikirim' => [[], 'corrected_check_in'],
        ];
    }

    /**
     * @param  array<string, string>  $times
     */
    #[DataProvider('invalidTimes')]
    public function test_invalid_or_empty_corrected_times_are_rejected_with_422(array $times, string $errorField): void
    {
        Sanctum::actingAs($this->makeWorker());

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-10', 'reason' => 'Lupa absen', ...$times])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorField]);

        $this->assertSame(0, AttendanceCorrection::count());
    }

    public function test_the_hh_mm_time_the_mobile_app_sends_is_accepted(): void
    {
        Sanctum::actingAs($this->makeWorker());

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-10', 'corrected_check_out' => '17:00', 'reason' => 'Lupa absen pulang'])
            ->assertCreated();

        $this->assertSame('2026-11-10 17:00:00', AttendanceCorrection::firstOrFail()->corrected_check_out->toDateTimeString());
    }

    public function test_update_rejects_a_broken_time_and_clearing_the_only_time(): void
    {
        $worker = $this->makeWorker();
        Sanctum::actingAs($worker);

        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-10', 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/attendance-corrections/{$id}", ['corrected_check_in' => '25:61'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in']);

        $this->putJson("/api/v1/attendance-corrections/{$id}", ['corrected_check_in' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['corrected_check_in']);

        $this->putJson("/api/v1/attendance-corrections/{$id}", ['corrected_check_in' => '', 'corrected_check_out' => '17:00'])
            ->assertOk();
    }

    public function test_a_second_correction_for_the_same_date_is_refused_until_the_first_is_withdrawn(): void
    {
        Sanctum::actingAs($this->makeWorker());

        $firstId = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-10', 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen'])
            ->assertCreated()
            ->json('data.id');

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-10', 'corrected_check_in' => '08:20', 'reason' => 'Lupa absen lagi'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', AttendanceCorrection::DUPLICATE_MESSAGE);

        $this->assertSame(1, AttendanceCorrection::count());

        $this->putJson("/api/v1/attendance-corrections/{$firstId}", ['corrected_check_in' => '08:05'])
            ->assertOk();

        AttendanceCorrection::whereKey($firstId)->update(['status' => 'cancelled']);

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-10', 'corrected_check_in' => '08:20', 'reason' => 'Lupa absen lagi'])
            ->assertCreated();
    }

    public function test_moving_a_correction_onto_a_date_that_already_has_one_is_refused(): void
    {
        Sanctum::actingAs($this->makeWorker());

        $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-10', 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen'])
            ->assertCreated();
        $secondId = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-11', 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen'])
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/v1/attendance-corrections/{$secondId}", ['date' => '2026-11-10'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', AttendanceCorrection::DUPLICATE_MESSAGE);
    }

    public function test_update_on_someone_elses_correction_is_a_404_even_when_it_clears_both_times(): void
    {
        $owner = $this->makeWorker();
        $other = AttendanceCorrection::create(['employee_id' => $owner->employee->id, 'date' => '2026-11-10', 'corrected_check_in' => '08:00', 'reason' => 'Lupa absen', 'status' => 'pending']);

        Sanctum::actingAs($this->makeWorker());

        $this->putJson("/api/v1/attendance-corrections/{$other->id}", ['corrected_check_in' => ''])
            ->assertNotFound();

        $this->putJson('/api/v1/attendance-corrections/999999', ['corrected_check_in' => ''])
            ->assertNotFound();
    }

    public function test_panel_create_form_requires_at_least_one_corrected_time(): void
    {
        User::factory()->create()->assignRole('Approver');
        $this->actingAs($this->makeWorker());

        Livewire::test(CreateAttendanceCorrection::class)
            ->fillForm(['date' => '2026-11-10', 'reason' => 'Lupa absen', 'corrected_check_in' => null, 'corrected_check_out' => null])
            ->call('create')
            ->assertHasFormErrors(['corrected_check_in' => 'required_without', 'corrected_check_out' => 'required_without']);

        $this->assertSame(0, AttendanceCorrection::count());

        Livewire::test(CreateAttendanceCorrection::class)
            ->fillForm(['date' => '2026-11-10', 'reason' => 'Lupa absen', 'corrected_check_in' => null, 'corrected_check_out' => '17:00'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-11-10 17:00:00', AttendanceCorrection::sole()->corrected_check_out->toDateTimeString());
    }

    public function test_approved_night_correction_keeps_the_check_out_on_the_next_day(): void
    {
        $worker = $this->makeNightWorker();
        Sanctum::actingAs($worker);

        $id = $this->postJson('/api/v1/attendance-corrections', ['date' => '2026-11-10', 'corrected_check_in' => '22:00', 'corrected_check_out' => '06:00', 'reason' => 'Lupa absen shift malam'])
            ->assertCreated()
            ->json('data.id');

        $correction = AttendanceCorrection::findOrFail($id);
        $this->assertSame('2026-11-11 06:00:00', $correction->corrected_check_out->toDateTimeString());

        $correction->applyCorrection();

        $attendance = Attendance::where('employee_id', $worker->employee->id)->firstOrFail();
        $this->assertSame('2026-11-10 22:00:00', $attendance->check_in->toDateTimeString());
        $this->assertSame('2026-11-11 06:00:00', $attendance->check_out->toDateTimeString());
        $this->assertSame(480, $attendance->workedMinutes());
    }

    public function test_full_datetime_check_out_on_the_next_day_stays_on_the_next_day(): void
    {
        $worker = $this->makeNightWorker();

        $correction = AttendanceCorrection::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-10', 'corrected_check_in' => '2026-11-10 22:00:00', 'corrected_check_out' => '2026-11-11 06:00:00', 'reason' => 'Lupa absen', 'status' => 'pending']);
        $correction->applyCorrection();

        $this->assertSame(480, Attendance::where('employee_id', $worker->employee->id)->firstOrFail()->workedMinutes());
    }

    public function test_day_correction_is_unchanged(): void
    {
        $worker = $this->makeWorker();

        AttendanceCorrection::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-10', 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'Lupa absen', 'status' => 'pending'])->applyCorrection();

        $attendance = Attendance::where('employee_id', $worker->employee->id)->firstOrFail();
        $this->assertSame('2026-11-10 17:00:00', $attendance->check_out->toDateTimeString());
        $this->assertSame(540, $attendance->workedMinutes());
    }

    public function test_check_out_only_correction_closes_an_overnight_row_on_the_next_day(): void
    {
        $worker = $this->makeNightWorker();
        Attendance::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-10', 'segment' => 1, 'check_in' => '2026-11-10 22:00:00', 'status' => 'on_time']);

        AttendanceCorrection::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-10', 'corrected_check_out' => '06:00', 'reason' => 'Lupa absen pulang', 'status' => 'pending'])->applyCorrection();

        $attendance = Attendance::where('employee_id', $worker->employee->id)->sole();
        $this->assertSame('2026-11-11 06:00:00', $attendance->check_out->toDateTimeString());
        $this->assertSame(480, $attendance->workedMinutes());
    }

    public function test_check_out_only_correction_of_a_night_shift_without_check_in_lands_on_the_next_day(): void
    {
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'N', 'start_time' => '22:00', 'end_time' => '06:00']);
        $worker = $this->makeWorker();
        $worker->employee->update(['shift_id' => $night->id]);

        AttendanceCorrection::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-10', 'corrected_check_out' => '06:00', 'reason' => 'Lupa absen pulang', 'status' => 'pending'])->applyCorrection();

        $this->assertSame('2026-11-11 06:00:00', Attendance::where('employee_id', $worker->employee->id)->sole()->check_out->toDateTimeString());
    }

    public function test_approving_a_legacy_correction_without_any_time_is_refused_and_changes_nothing(): void
    {
        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $approver->grantDataAccess(DataScope::All);
        $worker = $this->makeWorker();
        Attendance::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-10', 'segment' => 1, 'check_in' => '2026-11-10 08:00:00', 'status' => 'on_time']);

        $correction = AttendanceCorrection::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-10', 'reason' => 'Data lama tanpa jam', 'status' => 'pending']);
        $service = app(ApprovalFlowService::class);
        $service->generateSteps($correction, 'correction');

        try {
            $service->approveStep($correction->fresh(), $approver);
            $this->fail('Approving a correction without any corrected time must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('tidak berisi jam masuk maupun jam pulang', $exception->errors()['approve'][0]);
        }

        $correction->refresh();
        $this->assertSame('pending', $correction->status);
        $this->assertNull($correction->attendance_id);
        $this->assertSame('pending', $correction->approvalSteps()->sole()->status);

        $attendance = Attendance::where('employee_id', $worker->employee->id)->sole();
        $this->assertFalse((bool) $attendance->is_corrected);
        $this->assertNull($attendance->check_out);
    }

    public function test_applying_a_correction_without_any_time_creates_no_attendance(): void
    {
        $worker = $this->makeWorker();
        $correction = AttendanceCorrection::create(['employee_id' => $worker->employee->id, 'date' => '2026-11-10', 'reason' => 'Data lama tanpa jam', 'status' => 'pending']);

        $this->expectException(ValidationException::class);

        try {
            $correction->applyCorrection();
        } finally {
            $this->assertSame(0, Attendance::count());
            $this->assertSame('pending', $correction->fresh()->status);
        }
    }

    /**
     * A worker on a 22:00 - 06:00 shift, whose check-out before the check-in lands on the next day.
     */
    private function makeNightWorker(): User
    {
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'NW', 'start_time' => '22:00', 'end_time' => '06:00']);
        $worker = $this->makeWorker();
        $worker->employee->update(['shift_id' => $night->id]);

        return $worker->fresh();
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
            'nip' => 'KRS-'.$user->id,
            'full_name' => "Pekerja {$user->id}",
            'status' => 'active',
        ]);

        return $user->fresh();
    }
}
