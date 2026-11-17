<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\CompanyLocationResource\Pages\EditCompanyLocation;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceCheckService;
use App\Services\CompanyPolicyService;
use App\Services\GeofenceService;
use App\Services\TenantOnboardingService;
use App\Services\WorkerAccountService;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for the attendance findings of the daily-ops QA round: GPS that could be bypassed,
 * overnight shifts after midnight, expired contracts, platform-unique login emails and double check-ins.
 */
class AttendanceDailyFixesTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.face.driver' => 'basic',
            'services.onesignal.app_id' => null,
            'services.onesignal.rest_api_key' => null,
        ]);
        Http::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));

        $this->tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Absen', 'code' => 'ABSEN', 'is_active' => true]);
        $this->company->tenant_id = $this->tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);

        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'code' => 'OPS', 'is_active' => true]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function setPolicy(array $attributes): void
    {
        $this->company->policy()->update([
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            ...$attributes,
        ]);
        $this->company->unsetRelation('policy');
    }

    private function employee(): Employee
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_number' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Kasir'])->id,
            'nip' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'full_name' => 'Pekerja Absen',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function freelancer(array $attributes = []): Freelancer
    {
        return Freelancer::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'freelancer_number' => 'FL-ABSEN-0001',
            'full_name' => 'Freelancer Absen',
            'phone' => '081200000001',
            'start_date' => '2026-11-01',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    private function location(array $attributes = []): CompanyLocation
    {
        return CompanyLocation::create([
            'company_id' => $this->company->id,
            'name' => 'Outlet Satu',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'radius_meters' => 50,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function pic(): User
    {
        return app(TenantOnboardingService::class)->createPic($this->tenant, 'PIC Klien A', fake()->unique()->safeEmail(), '081200000002')['user']->fresh();
    }

    // ── C2: GPS cannot be bypassed by having no coordinate ───────────────────

    public function test_geofence_is_not_valid_when_gps_is_required_and_no_coordinate_is_set(): void
    {
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);

        $result = GeofenceService::validateCompanyGeofence($this->company->fresh(), -6.65, 106.8);

        $this->assertFalse($result['is_valid']);
        $this->assertSame('Lokasi absen belum diatur. Hubungi admin.', $result['message']);
    }

    public function test_geofence_without_coordinates_stays_valid_when_gps_is_not_required(): void
    {
        $this->setPolicy(['require_gps' => false]);

        $this->assertTrue(GeofenceService::validateCompanyGeofence($this->company->fresh(), -6.65, 106.8)['is_valid']);
    }

    public function test_check_in_without_any_coordinate_while_gps_is_required_goes_to_approval(): void
    {
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);
        $employee = $this->employee();
        Sanctum::actingAs($employee->user);

        $response = $this->postJson('/api/v1/attendance/check-in', ['latitude' => -6.65, 'longitude' => 106.8])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value);

        $this->assertStringContainsString('lokasi absen belum diatur', (string) $response->json('message'));
        $this->assertTrue((bool) Attendance::query()->firstOrFail()->is_out_of_bounds);
    }

    public function test_api_refuses_switching_off_the_last_active_location_while_gps_is_required(): void
    {
        $location = $this->location();
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);
        Sanctum::actingAs($this->pic());

        $this->putJson("/api/v1/client/locations/{$location->id}", ['name' => 'Outlet Satu', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active' => CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE]);

        $this->assertTrue($location->fresh()->is_active);
    }

    public function test_api_allows_switching_off_a_location_when_another_coordinate_remains(): void
    {
        $location = $this->location();
        $this->location(['name' => 'Outlet Dua']);
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);
        Sanctum::actingAs($this->pic());

        $this->putJson("/api/v1/client/locations/{$location->id}", ['name' => 'Outlet Satu', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => false])
            ->assertOk();

        $this->assertFalse($location->fresh()->is_active);
    }

    public function test_api_allows_switching_off_the_last_location_when_gps_is_not_required(): void
    {
        $location = $this->location();
        $this->setPolicy(['require_gps' => false]);
        Sanctum::actingAs($this->pic());

        $this->putJson("/api/v1/client/locations/{$location->id}", ['name' => 'Outlet Satu', 'latitude' => -6.2, 'longitude' => 106.8, 'is_active' => false])
            ->assertOk();
    }

    public function test_api_refuses_clearing_hq_coordinates_while_gps_is_required_without_a_location(): void
    {
        $this->company->update(['latitude' => -6.2, 'longitude' => 106.8]);
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);
        Sanctum::actingAs($this->pic());

        $this->putJson("/api/v1/client/companies/{$this->company->id}", ['name' => 'PT Absen', 'code' => 'ABSEN', 'latitude' => null, 'longitude' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude' => CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE]);

        $this->assertNotNull($this->company->fresh()->latitude);

        // Leaving the coordinates out of the request keeps them and is accepted.
        $this->putJson("/api/v1/client/companies/{$this->company->id}", ['name' => 'PT Absen Baru', 'code' => 'ABSEN'])->assertOk();

        // With an active location the HQ coordinates may go.
        $this->location();
        $this->putJson("/api/v1/client/companies/{$this->company->id}", ['name' => 'PT Absen', 'code' => 'ABSEN', 'latitude' => null, 'longitude' => null])->assertOk();
        $this->assertNull($this->company->fresh()->latitude);
    }

    public function test_panel_refuses_switching_off_the_last_active_location_while_gps_is_required(): void
    {
        $location = $this->location();
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);
        $this->actingAs($this->pic());

        Livewire::test(EditCompanyLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->assertTrue($location->fresh()->is_active);
    }

    public function test_panel_refuses_deleting_the_last_active_location_while_gps_is_required(): void
    {
        $location = $this->location();
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);
        $this->actingAs($this->pic());

        Livewire::test(EditCompanyLocation::class, ['record' => $location->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified(CompanyPolicyService::GPS_WITHOUT_LOCATION_MESSAGE);

        $this->assertModelExists($location);
    }

    public function test_panel_deletes_a_location_when_hq_coordinates_remain(): void
    {
        $location = $this->location();
        $this->company->update(['latitude' => -6.3, 'longitude' => 106.9]);
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);
        $this->actingAs($this->pic());

        Livewire::test(EditCompanyLocation::class, ['record' => $location->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($location);
    }

    public function test_panel_company_form_keeps_hq_coordinates_while_gps_is_required_without_a_location(): void
    {
        $this->company->update(['latitude' => -6.2, 'longitude' => 106.8]);
        $this->setPolicy(['require_gps' => true, 'geofence_radius_meters' => 50]);
        $this->actingAs($this->pic());

        Livewire::test(EditCompany::class, ['record' => $this->company->getRouteKey()])
            ->fillForm(['latitude' => null, 'longitude' => null])
            ->call('save')
            ->assertHasFormErrors(['latitude' => 'required', 'longitude' => 'required']);

        $this->assertNotNull($this->company->fresh()->latitude);
    }

    // ── C3: overnight shift after midnight ───────────────────────────────────

    private function openOvernightRow(Employee $employee, ?CompanyLocation $location = null, bool $nightShift = true): Attendance
    {
        $shift = $nightShift
            ? Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'ML', 'start_time' => '22:00', 'end_time' => '06:00'])
            : Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'code' => 'SG', 'start_time' => '14:00', 'end_time' => '22:00']);
        $this->travelTo(Carbon::parse('2026-11-17 02:00:00'));

        return Attendance::create([
            'employee_id' => $employee->id,
            'date' => self::MONDAY,
            'segment' => 1,
            'shift_id' => $shift->id,
            'company_location_id' => $location?->id,
            'check_in' => Carbon::parse(self::MONDAY.($nightShift ? ' 21:55:00' : ' 13:55:00')),
            'status' => AttendanceStatus::OnTime,
            'late_minutes' => 0,
        ]);
    }

    public function test_open_overnight_attendance_is_found_after_midnight(): void
    {
        $employee = $this->employee();
        $row = $this->openOvernightRow($employee);

        $this->assertSame($row->id, app(AttendanceCheckService::class)->openOvernightAttendance($employee->user)?->id);

        $row->update(['check_out' => now()]);
        $this->assertNull(app(AttendanceCheckService::class)->openOvernightAttendance($employee->user));
    }

    public function test_today_endpoint_offers_check_out_of_the_open_overnight_row(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $employee = $this->employee();
        $outlet = $this->location();
        $row = $this->openOvernightRow($employee, $outlet);
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('date', '2026-11-17')
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', false)
            ->assertJsonPath('attendance.id', $row->id)
            ->assertJsonPath('attendance.date', self::MONDAY)
            ->assertJsonPath('check_out_outlet.id', $outlet->id);
    }

    public function test_today_endpoint_starts_fresh_once_the_overnight_row_is_checked_out(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $employee = $this->employee();
        $this->openOvernightRow($employee)->update(['check_out' => now()]);
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('attendance', null);
    }

    public function test_new_check_in_is_refused_while_the_overnight_row_is_open(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $employee = $this->employee();
        $row = $this->openOvernightRow($employee);
        Sanctum::actingAs($employee->user);

        $this->postJson('/api/v1/attendance/check-in')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Selesaikan absen pulang shift semalam dulu.');

        $this->postJson('/api/v1/attendance/check-out')->assertOk()->assertJsonPath('attendance.id', $row->id);

        // Today's check-in opens an hour before the company hours (08:00).
        $this->travelTo(Carbon::parse('2026-11-17 07:30:00'));
        $this->postJson('/api/v1/attendance/check-in')->assertOk()->assertJsonPath('attendance.date', '2026-11-17');
        $this->assertSame(2, Attendance::query()->where('employee_id', $employee->id)->count());
    }

    public function test_forgotten_check_out_of_a_day_shift_does_not_block_todays_check_in(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $employee = $this->employee();
        $row = $this->openOvernightRow($employee, nightShift: false);
        $this->travelTo(Carbon::parse('2026-11-17 07:55:00'));
        Sanctum::actingAs($employee->user);

        $this->assertNull(app(AttendanceCheckService::class)->openOvernightAttendance($employee->user));
        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('has_checked_in', false);
        $this->postJson('/api/v1/attendance/check-in')->assertOk()->assertJsonPath('attendance.date', '2026-11-17');
        $this->assertNull($row->fresh()->check_out);
    }

    // ── C10: contract past its end date ──────────────────────────────────────

    public function test_intern_and_freelancer_past_their_end_date_are_not_active(): void
    {
        $accounts = app(WorkerAccountService::class);

        $this->assertFalse($accounts->isActive($this->freelancer(['end_date' => '2026-11-15'])));
        $this->assertTrue($accounts->isActive($this->freelancer(['freelancer_number' => 'FL-ABSEN-0002', 'end_date' => self::MONDAY])));
        $this->assertTrue($accounts->isActive($this->freelancer(['freelancer_number' => 'FL-ABSEN-0003', 'end_date' => null])));

        $intern = Intern::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'NIS-0001',
            'full_name' => 'Magang Lewat',
            'institution' => 'Universitas Uji',
            'start_date' => '2026-08-01',
            'end_date' => '2026-11-15',
            'status' => 'active',
        ]);
        $this->assertFalse($accounts->isActive($intern));
    }

    public function test_freelancer_past_the_end_date_cannot_check_in(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $freelancer = $this->freelancer(['end_date' => '2026-11-15']);
        $account = app(WorkerAccountService::class)->createAccount($freelancer);
        Sanctum::actingAs($account['user']);

        $this->postJson('/api/v1/attendance/check-in')
            ->assertForbidden()
            ->assertJsonPath('message', 'Status kerja Anda tidak aktif. Hubungi koordinator atau HR.');
        $this->assertSame(0, Attendance::query()->count());
    }

    // ── C7: platform-unique login emails ─────────────────────────────────────

    public function test_fallback_login_email_gets_a_suffix_when_another_klien_already_uses_it(): void
    {
        $otherTenant = Tenant::factory()->create(['name' => 'Klien B', 'code' => 'KB', 'is_active' => true]);
        User::factory()->create(['email' => 'fl-absen-0001@freelance.local'])->forceFill(['tenant_id' => $otherTenant->id])->save();
        User::factory()->create(['email' => 'fl-absen-0001-2@freelance.local'])->forceFill(['tenant_id' => $otherTenant->id])->save();

        $account = app(WorkerAccountService::class)->createAccount($this->freelancer());

        $this->assertSame('fl-absen-0001-3@freelance.local', $account['email']);
        $this->assertSame($this->tenant->id, (int) $account['user']->tenant_id);
    }

    public function test_fallback_login_email_has_no_suffix_when_it_is_free(): void
    {
        $account = app(WorkerAccountService::class)->createAccount($this->freelancer());

        $this->assertSame('fl-absen-0001@freelance.local', $account['email']);
    }

    public function test_taken_login_email_error_does_not_reveal_the_other_account(): void
    {
        User::factory()->create(['email' => 'budi@contoh.test']);
        $freelancer = $this->freelancer();

        try {
            app(WorkerAccountService::class)->createAccount($freelancer, 'budi@contoh.test');
            $this->fail('A taken email must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Email login sudah terpakai. Isi email lain.', $exception->getMessage());
            $this->assertStringNotContainsString('budi@contoh.test', $exception->getMessage());
        }

        $this->assertNull($freelancer->fresh()->user_id);
    }

    // ── C11: concurrent double check-in ──────────────────────────────────────

    public function test_check_in_that_loses_the_race_on_the_unique_index_is_a_409_and_its_photo_is_removed(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->setPolicy(['require_gps' => false]);
        $employee = $this->employee();
        Sanctum::actingAs($employee->user);

        // A concurrent request of the same tap inserts today's row between our read and our insert.
        Attendance::creating(function (Attendance $attendance): void {
            DB::table('attendances')->insert($attendance->getAttributes());
        });

        $this->post('/api/v1/attendance/check-in', ['photo' => UploadedFile::fake()->image('selfie.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Sudah melakukan check in hari ini.');

        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles());
    }

    public function test_uploads_are_removed_when_a_check_in_fails_with_an_unexpected_error(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->setPolicy(['require_gps' => false]);
        $employee = $this->employee();
        Sanctum::actingAs($employee->user);

        Attendance::creating(function (): void {
            throw new RuntimeException('Database tidak tersedia.');
        });

        $this->post('/api/v1/attendance/check-in', ['photo' => UploadedFile::fake()->image('selfie.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles());
        $this->assertSame(0, Attendance::query()->count());
    }
}
