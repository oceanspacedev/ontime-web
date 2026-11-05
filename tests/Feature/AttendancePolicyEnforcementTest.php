<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\GeofenceService;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AttendancePolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const HQ_LAT = -6.1753924;

    private const HQ_LNG = 106.8271528;

    private const OUTSIDE_LAT = -6.2000000;

    private const OUTSIDE_LNG = 106.8500000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    private function createCompany(bool $withCoordinates = true): Company
    {
        return Company::create(array_merge([
            'name' => 'PT Policy '.fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'is_active' => true,
        ], $withCoordinates ? ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG] : []));
    }

    private function createEmployee(Company $company): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $division = Division::create(['company_id' => $company->id, 'name' => 'IT', 'code' => 'IT'.$company->id, 'is_active' => true]);
        $jobLevel = JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1]);
        $jobTitle = JobTitle::firstOrCreate(['name' => 'Developer']);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP'.$user->id,
            'full_name' => 'Test Employee',
            'join_date' => now(),
            'status' => 'permanent',
        ]);
    }

    private function createApprover(Employee $employee): User
    {
        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $employee->company_id, $employee->division_id);

        return $approver;
    }

    // ── API: face recognition implies a photo ────────────────────────────────

    public function test_api_check_in_requires_photo_when_only_face_recognition_is_enabled(): void
    {
        $company = $this->createCompany();
        $company->policy()->update(['require_photo' => false, 'require_face_recognition' => true]);
        $employee = $this->createEmployee($company);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-in')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Foto selfie absensi wajib diunggah.');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_api_check_in_tells_worker_to_register_master_face_first(): void
    {
        $company = $this->createCompany();
        $company->policy()->update(['require_face_recognition' => true, 'face_fail_action' => 'approval']);
        $employee = $this->createEmployee($company);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-in', ['photo' => UploadedFile::fake()->image('selfie.jpg', 100, 100)])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Foto Master Wajah belum didaftarkan'));

        $this->assertDatabaseCount('attendances', 0);
    }

    // ── API: check-out verifies the face like the web flow ───────────────────

    public function test_api_check_out_runs_face_recognition(): void
    {
        $company = $this->createCompany();
        $company->policy()->update(['require_face_recognition' => true, 'face_fail_action' => 'reject']);
        $employee = $this->createEmployee($company);

        $masterPath = UploadedFile::fake()->image('master.jpg', 100, 100)->store('master-faces', 'public');
        $employee->update(['master_face_photo' => $masterPath]);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-in', ['photo' => UploadedFile::fake()->image('in.jpg', 100, 100)])
            ->assertStatus(200);

        // Master face removed between check-in and check-out: check-out must now refuse, not silently pass.
        $employee->update(['master_face_photo' => null]);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-out', ['photo' => UploadedFile::fake()->image('out.jpg', 100, 100)])
            ->assertStatus(422);

        $this->assertNull(Attendance::where('employee_id', $employee->id)->first()->check_out);

        $employee->update(['master_face_photo' => $masterPath]);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-out', ['photo' => UploadedFile::fake()->image('out.jpg', 100, 100)])
            ->assertStatus(200)
            ->assertJsonPath('attendance.is_face_verified', true);
    }

    public function test_api_check_out_without_photo_is_refused_when_face_recognition_is_enabled(): void
    {
        $company = $this->createCompany();
        $employee = $this->createEmployee($company);

        Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => now()->setTime(8, 0, 0),
            'status' => AttendanceStatus::OnTime,
        ]);

        $company->policy()->update(['require_photo' => false, 'require_face_recognition' => true]);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-out')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Foto selfie absensi wajib diunggah.');
    }

    // ── Approval: a second out-of-bounds event after an approved round ──────

    public function test_check_out_outside_geofence_after_approved_check_in_opens_a_new_approval_round(): void
    {
        $company = $this->createCompany();
        $company->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 100]);
        $employee = $this->createEmployee($company);
        $approver = $this->createApprover($employee);
        $service = app(ApprovalFlowService::class);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-in', ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG])
            ->assertStatus(200)
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value);

        $attendance = Attendance::where('employee_id', $employee->id)->first();
        $service->approveStep($attendance, $approver);
        $this->assertNotEquals(AttendanceStatus::PendingApproval, $attendance->fresh()->status);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-out', ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG])
            ->assertStatus(200)
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value);

        $attendance = $attendance->fresh();
        $this->assertCount(2, $attendance->approvalSteps, 'The approved round is kept and a new pending step is added.');
        $this->assertEquals(2, $attendance->current_step);
        $this->assertTrue($service->isUserAuthorizedToApprove($attendance, $approver), 'The approver can act on the new round instead of being locked out.');

        $service->approveStep($attendance, $approver);
        $this->assertNotEquals(AttendanceStatus::PendingApproval, $attendance->fresh()->status);
    }

    public function test_check_out_outside_geofence_while_check_in_is_still_pending_reuses_the_open_round(): void
    {
        $company = $this->createCompany();
        $company->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 100]);
        $employee = $this->createEmployee($company);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-in', ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG])
            ->assertStatus(200);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-out', ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG])
            ->assertStatus(200);

        $this->assertCount(1, Attendance::where('employee_id', $employee->id)->first()->approvalSteps);
    }

    // ── Geofence service guards ─────────────────────────────────────────────

    public function test_zero_radius_falls_back_to_the_default_instead_of_locking_everyone_out(): void
    {
        $company = $this->createCompany();
        $company->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 0]);

        $result = GeofenceService::validateCompanyGeofence($company->fresh(), self::HQ_LAT, self::HQ_LNG);

        $this->assertTrue($result['is_valid']);
        $this->assertEquals(GeofenceService::DEFAULT_RADIUS_METERS, $result['allowed_radius']);
    }

    public function test_has_configured_locations_recognises_hq_or_active_branch(): void
    {
        $bare = $this->createCompany(withCoordinates: false);
        $this->assertFalse(GeofenceService::hasConfiguredLocations($bare));

        CompanyLocation::create([
            'company_id' => $bare->id,
            'name' => 'Cabang',
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
            'is_active' => false,
        ]);
        $this->assertFalse(GeofenceService::hasConfiguredLocations($bare->fresh()), 'An inactive branch does not count.');

        $bare->locations()->update(['is_active' => true]);
        $this->assertTrue(GeofenceService::hasConfiguredLocations($bare->fresh()));

        $this->assertTrue(GeofenceService::hasConfiguredLocations($this->createCompany()));
    }

    public function test_api_treats_null_coordinates_as_missing_but_zero_as_a_value(): void
    {
        $company = $this->createCompany();
        $company->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 100]);
        $employee = $this->createEmployee($company);

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-in', ['latitude' => self::HQ_LAT])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Lokasi GPS wajib diisi.');

        $this->actingAs($employee->user->fresh())
            ->postJson('/api/v1/attendance/check-in', ['latitude' => 0, 'longitude' => 0])
            ->assertStatus(200)
            ->assertJsonPath('attendance.is_out_of_bounds', true);
    }

    // ── Policy form ─────────────────────────────────────────────────────────

    private function actingAsSuperadmin(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);
    }

    /**
     * @return array<string, mixed>
     */
    private function policyFormData(array $overrides = []): array
    {
        return array_merge([
            'late_tolerance_minutes' => 15,
            'require_photo' => false,
            'require_gps' => false,
            'require_face_recognition' => false,
            'face_match_threshold' => 60,
            'face_fail_action' => 'reject',
            'geofence_radius_meters' => 100,
            'annual_leave_quota' => 12,
            'default_approval_stages' => 1,
        ], $overrides);
    }

    public function test_policy_form_refuses_gps_requirement_without_any_coordinates(): void
    {
        $this->actingAsSuperadmin();
        $company = $this->createCompany(withCoordinates: false);

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($company), data: $this->policyFormData(['require_gps' => true]))
            ->assertHasFormErrors(['require_gps']);

        $this->assertFalse($company->policy->fresh()->require_gps);
    }

    public function test_policy_form_accepts_gps_requirement_when_hq_coordinates_exist(): void
    {
        $this->actingAsSuperadmin();
        $company = $this->createCompany();

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($company), data: $this->policyFormData(['require_gps' => true, 'geofence_radius_meters' => 150]))
            ->assertHasNoFormErrors();

        $this->assertTrue($company->policy->fresh()->require_gps);
        $this->assertEquals(150, $company->policy->fresh()->geofence_radius_meters);
    }

    public function test_policy_form_forces_photo_requirement_when_face_recognition_is_enabled(): void
    {
        $this->actingAsSuperadmin();
        $company = $this->createCompany();

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($company), data: $this->policyFormData(['require_photo' => false, 'require_face_recognition' => true]))
            ->assertHasNoFormErrors();

        $policy = $company->policy->fresh();
        $this->assertTrue($policy->require_face_recognition);
        $this->assertTrue($policy->require_photo);
    }

    public function test_policy_form_rejects_radius_below_one_meter(): void
    {
        $this->actingAsSuperadmin();
        $company = $this->createCompany();

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($company), data: $this->policyFormData(['require_gps' => true, 'geofence_radius_meters' => 0]))
            ->assertHasFormErrors(['geofence_radius_meters']);
    }
}
