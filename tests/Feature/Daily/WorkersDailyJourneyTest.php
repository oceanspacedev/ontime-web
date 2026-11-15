<?php

namespace Tests\Feature\Daily;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Pages\AbsenHariIni;
use App\Filament\Pages\ApprovalSaya;
use App\Filament\Pages\JadwalMingguan;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\WorkerAccountService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QA daily journey for the worker roles (Employee, Intern, Freelancer) through the mobile API and
 * the web panel: check-in and check-out per attendance mode, history, requests, and everything a
 * worker must never be able to do (manage workers, approve, see other workers or other Klien).
 *
 * The Superadmin is never involved: a Klien Approver handles the approvals of the day.
 */
class WorkersDailyJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const HQ_LAT = -6.1753924;

    private const HQ_LNG = 106.8271528;

    private const OUTSIDE_LAT = -6.2000000;

    private const OUTSIDE_LNG = 106.8500000;

    private const MONDAY = '2026-11-16';

    private Tenant $tenant;

    private Company $company;

    private Division $outlet;

    private User $approver;

    private Employee $employee;

    private Intern $intern;

    private Freelancer $freelancer;

    private Employee $colleague;

    private Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Notification::fake();
        Mail::fake();
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake();

        config([
            'filesystems.default' => 'public',
            'services.face.driver' => 'basic',
        ]);

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:50:00'));

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Harian']);
        $this->company = $this->makeCompany($this->tenant, 'PT Harian', 'HRN');
        $this->outlet = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet Harian', 'code' => 'OH', 'is_active' => true]);

        $this->approver = $this->makeAccount($this->tenant, 'Approver');
        $this->approver->grantDataAccess(DataScope::Companies, companyIds: [$this->company->id]);

        $this->employee = $this->makeEmployee($this->company, $this->outlet, 'Karyawan Harian');
        $this->colleague = $this->makeEmployee($this->company, $this->outlet, 'Rekan Harian');
        $this->intern = $this->makeIntern($this->company, $this->outlet);
        $this->freelancer = $this->makeFreelancer($this->company, $this->outlet);

        $otherTenant = Tenant::factory()->create(['name' => 'Klien Lain']);
        $otherCompany = $this->makeCompany($otherTenant, 'PT Lain', 'LAIN');
        $otherDivision = Division::create(['company_id' => $otherCompany->id, 'name' => 'Lain', 'code' => 'LN', 'is_active' => true]);
        $this->outsider = $this->makeEmployee($otherCompany, $otherDivision, 'Pekerja Klien Lain');
    }

    // ── Setup helpers ────────────────────────────────────────────────────────

    private function makeCompany(Tenant $tenant, string $name, string $code): Company
    {
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $code,
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
            'is_active' => true,
        ]);

        app(ApprovalFlowService::class)->createDefaultFlows($company);

        return $company;
    }

    private function makeAccount(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function makeEmployee(Company $company, Division $division, string $name): Employee
    {
        $user = $this->makeAccount($company->tenant, 'Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'EMP'.$user->id,
            'full_name' => $name,
            'join_date' => now()->subYear(),
            'status' => 'permanent',
        ]);
    }

    private function makeIntern(Company $company, Division $division): Intern
    {
        $user = $this->makeAccount($company->tenant, 'Intern');

        return Intern::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'nis' => 'NIS'.$user->id,
            'full_name' => 'Magang Harian',
            'institution' => 'Universitas Uji',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeFreelancer(Company $company, Division $division, array $overrides = []): Freelancer
    {
        $user = $this->makeAccount($company->tenant, 'Freelancer');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'freelancer_number' => 'FRL'.$user->id,
            'full_name' => 'Freelancer Harian',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function setPolicy(array $attributes): void
    {
        $this->company->policy->update([
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
            'geofence_radius_meters' => 100,
            ...$attributes,
        ]);
    }

    /**
     * Sign in as the worker with a freshly loaded account, like a new request from the app.
     */
    private function actAs(Employee|Intern|Freelancer $worker): User
    {
        $user = User::findOrFail($worker->user_id);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Multipart post like the mobile app sends it.
     *
     * @param  array<string, mixed>  $data
     */
    private function postAs(Employee|Intern|Freelancer $worker, string $url, array $data = []): TestResponse
    {
        $this->actAs($worker);

        return $this->post($url, $data, ['Accept' => 'application/json']);
    }

    private function checkIn(Employee|Intern|Freelancer $worker, array $data = []): TestResponse
    {
        return $this->postAs($worker, '/api/v1/attendance/check-in', $data);
    }

    private function checkOut(Employee|Intern|Freelancer $worker, array $data = []): TestResponse
    {
        return $this->postAs($worker, '/api/v1/attendance/check-out', $data);
    }

    private function today(Employee|Intern|Freelancer $worker): TestResponse
    {
        $this->actAs($worker);

        return $this->getJson('/api/v1/attendance/today')->assertOk();
    }

    private function scheduleShift(Employee|Intern|Freelancer $worker, Shift $shift, string $date): void
    {
        $column = match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };

        ShiftSchedule::create([$column => $worker->id, 'date' => $date, 'shift_id' => $shift->id]);
    }

    /**
     * Forget the guards and the test session between token requests, so only the bearer token
     * authenticates (the mobile app has no session cookie).
     */
    private function signOutEverywhere(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }

    private function approveAsApprover(string $type, int $id): TestResponse
    {
        Sanctum::actingAs(User::findOrFail($this->approver->id));

        return $this->putJson("/api/v1/approvals/{$type}/{$id}/process", ['action' => 'approved']);
    }

    // ── Attendance: klik saja ────────────────────────────────────────────────

    public function test_employee_klik_saja_day_from_check_in_to_check_out(): void
    {
        $this->setPolicy(['require_gps' => false, 'require_photo' => false]);

        $this->today($this->employee)
            ->assertJsonPath('date', self::MONDAY)
            ->assertJsonPath('has_checked_in', false)
            ->assertJsonPath('schedule.start_time', '08:00')
            ->assertJsonPath('schedule.end_time', '17:00')
            ->assertJsonPath('schedule.is_day_off', false);

        $this->checkIn($this->employee)
            ->assertOk()
            ->assertJsonPath('message', 'Check in berhasil')
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('attendance.status', AttendanceStatus::OnTime->value);

        $this->today($this->employee)
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', false);

        $this->checkIn($this->employee)->assertStatus(409);

        $this->travelTo(Carbon::parse(self::MONDAY.' 17:05:00'));
        $this->checkOut($this->employee)->assertOk()->assertJsonPath('message', 'Check out berhasil');
        $this->checkOut($this->employee)->assertStatus(409);

        $this->today($this->employee)
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', true);

        $this->assertSame(1, Attendance::where('employee_id', $this->employee->id)->count());
    }

    public function test_late_check_in_is_recorded_as_late_after_the_tolerance(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:40:00'));

        $this->checkIn($this->intern)
            ->assertOk()
            ->assertJsonPath('attendance.status', AttendanceStatus::Late->value)
            ->assertJsonPath('attendance.late_minutes', 40);
    }

    public function test_check_out_without_check_in_is_refused(): void
    {
        $this->setPolicy(['require_gps' => false]);

        $this->checkOut($this->freelancer)->assertStatus(422)->assertJsonPath('message', 'Anda belum melakukan check in hari ini.');
    }

    // ── Attendance: GPS ──────────────────────────────────────────────────────

    public function test_gps_check_in_inside_the_geofence_is_on_time_and_missing_gps_is_refused(): void
    {
        $this->setPolicy(['require_gps' => true]);

        $this->checkIn($this->employee)->assertStatus(422)->assertJsonPath('message', 'Lokasi GPS wajib diisi.');
        $this->assertSame(0, Attendance::count());

        $this->checkIn($this->employee, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'accuracy' => 8, 'is_mocked' => '0'])
            ->assertOk()
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('attendance.is_out_of_bounds', false)
            ->assertJsonPath('attendance.status', AttendanceStatus::OnTime->value);
    }

    public function test_gps_check_in_outside_the_geofence_waits_for_the_klien_approver(): void
    {
        $this->setPolicy(['require_gps' => true]);

        $response = $this->checkIn($this->freelancer, ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG, 'is_mocked' => 'false'])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value)
            ->assertJsonPath('attendance.is_out_of_bounds', true);
        $this->assertContains('di luar area kantor', $response->json('approval_reasons'));
        $attendanceId = (int) $response->json('attendance.id');

        // The worker never sees it as something to approve, and cannot approve their own presence.
        $this->actAs($this->freelancer);
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonCount(0, 'geofence_attendances');
        $this->putJson("/api/v1/approvals/geofence/{$attendanceId}/process", ['action' => 'approved'])->assertStatus(422);
        $this->assertSame(AttendanceStatus::PendingApproval, Attendance::findOrFail($attendanceId)->status);

        // The Approver of the Klien sees it and approves it: the attendance becomes on time.
        Sanctum::actingAs(User::findOrFail($this->approver->id));
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonPath('geofence_attendances.0.id', $attendanceId);
        $this->approveAsApprover('geofence', $attendanceId)->assertOk();
        $this->assertSame(AttendanceStatus::OnTime, Attendance::findOrFail($attendanceId)->status);
    }

    public function test_gps_and_photo_mode_requires_a_selfie_uploaded_as_multipart(): void
    {
        $this->setPolicy(['require_gps' => true, 'require_photo' => true]);
        $gps = ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => '0'];

        $this->checkIn($this->employee, $gps)->assertStatus(422)->assertJsonPath('message', 'Foto selfie absensi wajib diunggah.');
        $this->checkIn($this->employee, [...$gps, 'photo' => UploadedFile::fake()->create('selfie.pdf', 10, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
        $this->assertSame(0, Attendance::count());

        $this->checkIn($this->employee, [...$gps, 'photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])->assertOk();

        $attendance = Attendance::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertNotNull($attendance->check_in_photo);
        Storage::disk('public')->assertExists($attendance->check_in_photo);
        $this->assertStringStartsWith('attendance/photos/', $attendance->check_in_photo);

        $this->travelTo(Carbon::parse(self::MONDAY.' 17:00:00'));
        $this->checkOut($this->employee, [...$gps, 'photo' => UploadedFile::fake()->image('pulang.jpg', 80, 80)])->assertOk();
        Storage::disk('public')->assertExists($attendance->fresh()->check_out_photo);
    }

    public function test_mock_location_is_refused_by_default(): void
    {
        $this->setPolicy(['require_gps' => true, 'mock_location_action' => 'reject']);

        $this->checkIn($this->intern, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => 'true'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Lokasi palsu (mock location) terdeteksi. Matikan aplikasi pemalsu lokasi lalu coba lagi.');

        $this->assertSame(0, Attendance::count());
    }

    public function test_mock_location_goes_to_approval_when_the_policy_says_so(): void
    {
        $this->setPolicy(['require_gps' => true, 'mock_location_action' => 'approval']);

        $response = $this->checkIn($this->intern, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => '1'])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('attendance.is_mock_location', true)
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value);

        $this->assertContains('lokasi palsu terdeteksi', $response->json('approval_reasons'));
    }

    // ── Attendance: liveness ─────────────────────────────────────────────────

    public function test_liveness_challenge_follows_the_company_mode_and_master_face(): void
    {
        $this->setPolicy(['require_gps' => false, 'require_photo' => false]);
        $this->actAs($this->employee);
        $this->postJson('/api/v1/attendance/liveness-challenge', ['purpose' => 'check_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Liveness tidak diwajibkan untuk badan usaha Anda.');

        config([
            'services.face.driver' => 'compreface',
            'services.face.compreface.url' => 'http://compreface.test',
            'services.face.compreface.verification_key' => 'verify-key',
            'services.face.compreface.detection_key' => 'detect-key',
        ]);
        $this->setPolicy(['require_liveness' => true]);

        // Without a master face the check-in is refused before any face service call.
        $this->checkIn($this->employee)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Foto Master Wajah belum didaftarkan. Silakan daftarkan Foto Master Wajah Anda terlebih dahulu.');

        $this->postAs($this->employee, '/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('master.jpg', 80, 80)])
            ->assertOk();

        $this->actAs($this->employee);
        $challenge = $this->postJson('/api/v1/attendance/liveness-challenge', ['purpose' => 'check_in'])->assertOk();
        $this->assertCount(3, $challenge->json('steps'));
        $this->assertNotEmpty($challenge->json('challenge_id'));

        $this->checkIn($this->employee, ['liveness_challenge_id' => $challenge->json('challenge_id')])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Verifikasi liveness wajib. Ambil foto sesuai langkah yang diminta.');
        $this->assertSame(0, Attendance::count());
    }

    // ── Attendance: shifts ───────────────────────────────────────────────────

    public function test_freelancer_split_shift_checks_in_twice_and_the_second_segment_opens_an_hour_before(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $split = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->outlet->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $this->scheduleShift($this->freelancer, $split, self::MONDAY);

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:40:00'));
        $this->checkIn($this->freelancer)->assertOk()->assertJsonPath('attendance.segment', 1);
        $this->checkIn($this->freelancer)->assertStatus(409);

        $this->travelTo(Carbon::parse(self::MONDAY.' 13:05:00'));
        $this->checkOut($this->freelancer)->assertOk();

        $this->today($this->freelancer)
            ->assertJsonPath('schedule.segment', 2)
            ->assertJsonPath('schedule.segment_count', 2)
            ->assertJsonPath('has_checked_in', false);

        $this->travelTo(Carbon::parse(self::MONDAY.' 15:00:00'));
        $this->checkIn($this->freelancer)->assertStatus(422)->assertJsonPath('message', 'Segmen kedua dimulai pukul 17:45. Check in dibuka pukul 16:45.');

        $this->travelTo(Carbon::parse(self::MONDAY.' 17:40:00'));
        $this->checkIn($this->freelancer)->assertOk()->assertJsonPath('attendance.segment', 2)->assertJsonPath('attendance.status', AttendanceStatus::OnTime->value);

        $this->travelTo(Carbon::parse(self::MONDAY.' 22:05:00'));
        $this->checkOut($this->freelancer)->assertOk()->assertJsonPath('attendance.segment', 2);
        $this->checkIn($this->freelancer)->assertStatus(409);

        $this->assertSame(2, Attendance::where('freelancer_id', $this->freelancer->id)->count());
    }

    public function test_intern_overnight_shift_checks_out_after_midnight_on_the_same_attendance(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'ML', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->scheduleShift($this->intern, $night, self::MONDAY);

        $this->travelTo(Carbon::parse(self::MONDAY.' 21:55:00'));
        $this->checkIn($this->intern)->assertOk()->assertJsonPath('attendance.status', AttendanceStatus::OnTime->value);

        $this->travelTo(Carbon::parse('2026-11-17 06:05:00'));
        $this->checkOut($this->intern)->assertOk()->assertJsonPath('attendance.date', self::MONDAY);

        $this->assertSame(1, Attendance::where('intern_id', $this->intern->id)->count());
        $this->assertNotNull(Attendance::where('intern_id', $this->intern->id)->firstOrFail()->check_out);
    }

    public function test_today_endpoint_shows_the_open_overnight_attendance_after_midnight(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'ML', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->scheduleShift($this->intern, $night, self::MONDAY);

        $this->travelTo(Carbon::parse(self::MONDAY.' 21:55:00'));
        $this->checkIn($this->intern)->assertOk();

        // At 02:00 the app must offer "Absen Pulang" for last night's shift, not "Absen Masuk".
        $this->travelTo(Carbon::parse('2026-11-17 02:00:00'));
        $this->today($this->intern)
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', false)
            ->assertJsonPath('attendance.date', self::MONDAY);
    }

    public function test_scheduled_day_off_is_shown_and_a_check_in_is_never_late(): void
    {
        $this->setPolicy(['require_gps' => false]);
        ShiftSchedule::create(['employee_id' => $this->employee->id, 'date' => self::MONDAY, 'is_day_off' => true]);

        $this->today($this->employee)->assertJsonPath('schedule.is_day_off', true);

        $this->travelTo(Carbon::parse(self::MONDAY.' 13:00:00'));
        $this->checkIn($this->employee)
            ->assertOk()
            ->assertJsonPath('attendance.status', AttendanceStatus::OnTime->value)
            ->assertJsonPath('attendance.late_minutes', 0);
    }

    // ── History ──────────────────────────────────────────────────────────────

    public function test_history_and_report_only_show_the_workers_own_attendance(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $this->checkIn($this->employee)->assertOk();
        $this->checkIn($this->colleague)->assertOk();
        $this->checkIn($this->outsider)->assertOk();
        $colleagueAttendance = Attendance::where('employee_id', $this->colleague->id)->firstOrFail();
        $outsiderAttendance = Attendance::where('employee_id', $this->outsider->id)->firstOrFail();
        $own = Attendance::where('employee_id', $this->employee->id)->firstOrFail();

        $this->actAs($this->employee);
        $this->getJson('/api/v1/attendances?month=11&year=2026')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('pagination.total', 1);
        $this->getJson("/api/v1/attendances/{$own->id}")->assertOk();
        $this->getJson("/api/v1/attendances/{$colleagueAttendance->id}")->assertForbidden();
        $this->getJson("/api/v1/attendances/{$outsiderAttendance->id}")->assertForbidden();

        $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('summary.total_present', 1);
    }

    // ── Leave ────────────────────────────────────────────────────────────────

    public function test_employee_leave_types_quota_and_attachment(): void
    {
        $this->actAs($this->employee);
        $leave = fn (array $data): TestResponse => $this->post('/api/v1/leave-requests', [
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-20',
            'reason' => 'Keperluan keluarga',
            ...$data,
        ], ['Accept' => 'application/json']);

        // Annual leave is off until HR turns it on for this employee.
        $leave(['leave_type' => 'annual_leave'])->assertStatus(422)->assertJsonValidationErrors('leave_type');

        $this->employee->update(['has_annual_leave' => true, 'annual_leave_quota' => 2]);
        $this->actAs($this->employee);
        $leave(['leave_type' => 'annual_leave', 'end_date' => '2026-11-23'])->assertStatus(422)
            ->assertJsonPath('message', 'Kuota cuti tahunan Anda tidak mencukupi (Sisa: 2 hari, Pengajuan: 4 hari).');
        $leave(['leave_type' => 'annual'])->assertCreated()->assertJsonPath('data.leave_type', 'annual_leave');

        $leave(['leave_type' => 'sick', 'attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');
        $sick = $leave(['leave_type' => 'sick', 'attachment' => UploadedFile::fake()->create('surat-dokter.pdf', 50, 'application/pdf')])
            ->assertCreated();
        $sickRecord = LeaveRequest::findOrFail($sick->json('data.id'));
        $this->assertNotNull($sickRecord->attachment);
        Storage::disk('public')->assertExists($sickRecord->attachment);
        $this->assertSame(1, $sickRecord->approvalSteps()->count());

        $leave(['leave_type' => 'permission', 'end_date' => '2026-11-19'])->assertStatus(422)->assertJsonValidationErrors('end_date');

        $this->getJson('/api/v1/leave-requests')
            ->assertOk()
            ->assertJsonPath('quota_summary.eligible', true)
            ->assertJsonPath('quota_summary.annual_leave_quota', 2)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_intern_and_freelancer_have_no_annual_leave_but_can_file_izin(): void
    {
        foreach ([[$this->intern, 'Peserta magang tidak memiliki jatah cuti tahunan. Gunakan jenis Izin.'], [$this->freelancer, 'Freelancer tidak memiliki jatah cuti tahunan. Gunakan jenis Izin.']] as [$worker, $message]) {
            $this->actAs($worker);
            $payload = ['start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'Urusan kampus'];

            $this->postJson('/api/v1/leave-requests', [...$payload, 'leave_type' => 'annual_leave'])
                ->assertStatus(422)
                ->assertJsonPath('message', $message);
            $this->postJson('/api/v1/leave-requests', [...$payload, 'leave_type' => 'permission'])->assertCreated();
            $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonPath('quota_summary.eligible', false)->assertJsonPath('pagination.total', 1);
        }

        // Interns have no team leave calendar; freelancers do.
        $this->actAs($this->intern);
        $this->getJson('/api/v1/kalender-cuti')->assertForbidden();
        $this->actAs($this->freelancer);
        $this->getJson('/api/v1/kalender-cuti')->assertOk();
    }

    public function test_worker_edits_and_cancels_a_pending_leave_but_not_an_approved_one(): void
    {
        $this->actAs($this->freelancer);
        $first = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'permission', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'Acara keluarga'])->assertCreated();
        $id = (int) $first->json('data.id');

        $this->putJson("/api/v1/leave-requests/{$id}", ['reason' => 'Acara keluarga besar', 'end_date' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.days_count', 2);

        $second = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-24', 'end_date' => '2026-11-24', 'reason' => 'Demam'])->assertCreated();
        $secondId = (int) $second->json('data.id');
        $this->deleteJson("/api/v1/leave-requests/{$secondId}")->assertOk();
        $this->assertNull(LeaveRequest::find($secondId));
        $this->assertDatabaseMissing('approval_request_steps', ['approvable_type' => (new LeaveRequest)->getMorphClass(), 'approvable_id' => $secondId]);

        $this->approveAsApprover('leave', $id)->assertOk();
        $this->assertSame('approved', LeaveRequest::findOrFail($id)->status);

        $this->actAs($this->freelancer);
        $this->putJson("/api/v1/leave-requests/{$id}", ['reason' => 'Ubah setelah disetujui'])->assertStatus(422);
        $this->deleteJson("/api/v1/leave-requests/{$id}")->assertStatus(422);
        $this->assertNotNull(LeaveRequest::find($id));
    }

    // ── Overtime, correction, resignation ────────────────────────────────────

    public function test_overtime_past_midnight_and_cancel_own_pending_overtime(): void
    {
        $this->actAs($this->employee);

        $this->postJson('/api/v1/overtime-requests', ['date' => self::MONDAY, 'start_time' => '20:00', 'end_time' => '20:00', 'reason' => 'Stock opname'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_time');

        $created = $this->postJson('/api/v1/overtime-requests', ['date' => self::MONDAY, 'start_time' => '20:00', 'end_time' => '01:00', 'reason' => 'Stock opname'])
            ->assertCreated();
        $overtime = OvertimeRequest::findOrFail($created->json('data.id'));
        $this->assertSame(300, (int) $overtime->duration_minutes);
        $this->assertSame('pending', $overtime->status);

        $this->getJson('/api/v1/overtime-requests')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->deleteJson("/api/v1/overtime-requests/{$overtime->id}")->assertOk();
        $this->assertNull(OvertimeRequest::find($overtime->id));
    }

    public function test_correction_for_a_missed_day_becomes_attendance_after_approval(): void
    {
        $this->actAs($this->intern);
        $created = $this->postJson('/api/v1/attendance-corrections', [
            'date' => '2026-11-13',
            'corrected_check_in' => '08:00',
            'corrected_check_out' => '17:00',
            'reason' => 'Lupa absen, HP mati',
        ])->assertCreated();
        $correctionId = (int) $created->json('data.id');

        $this->assertNull(Attendance::where('intern_id', $this->intern->id)->first());

        $this->approveAsApprover('correction', $correctionId)->assertOk();

        $this->assertSame('approved', AttendanceCorrection::findOrFail($correctionId)->status);
        $attendance = Attendance::where('intern_id', $this->intern->id)->firstOrFail();
        $this->assertTrue($attendance->is_corrected);
        $this->assertSame('2026-11-13', $attendance->date->toDateString());
        $this->assertSame('2026-11-13 08:00', Carbon::parse($attendance->check_in)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-13 17:00', Carbon::parse($attendance->check_out)->format('Y-m-d H:i'));
        $this->assertSame(AttendanceStatus::OnTime, $attendance->status);
    }

    public function test_resignation_is_for_employees_only_and_one_at_a_time(): void
    {
        $payload = ['resignation_date' => self::MONDAY, 'last_working_day' => '2026-12-16', 'reason' => 'Melanjutkan studi'];

        foreach ([$this->intern, $this->freelancer] as $worker) {
            $this->actAs($worker);
            $this->postJson('/api/v1/resignations', $payload)->assertStatus(422)->assertJsonPath('message', 'Profil Karyawan tidak ditemukan.');
        }

        $this->actAs($this->employee);
        $this->postJson('/api/v1/resignations', [...$payload, 'last_working_day' => '2026-11-01'])->assertStatus(422)->assertJsonValidationErrors('last_working_day');
        $id = (int) $this->postJson('/api/v1/resignations', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/resignations', $payload)->assertStatus(422);

        $this->getJson('/api/v1/resignations')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->deleteJson("/api/v1/resignations/{$id}")->assertOk();
        $this->assertNull(Resignation::find($id));
        $this->postJson('/api/v1/resignations', $payload)->assertCreated();
    }

    // ── Negative permissions ─────────────────────────────────────────────────

    public function test_worker_cannot_read_change_or_approve_other_workers_requests(): void
    {
        $this->actAs($this->colleague);
        $colleagueLeave = (int) $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'Sakit'])->assertCreated()->json('data.id');
        $colleagueOvertime = (int) $this->postJson('/api/v1/overtime-requests', ['date' => self::MONDAY, 'start_time' => '18:00', 'end_time' => '20:00', 'reason' => 'Lembur'])->assertCreated()->json('data.id');

        $this->actAs($this->outsider);
        $outsiderLeave = (int) $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'Sakit'])->assertCreated()->json('data.id');

        foreach ([$this->employee, $this->intern, $this->freelancer] as $worker) {
            $this->actAs($worker);

            $this->getJson("/api/v1/leave-requests/{$colleagueLeave}")->assertForbidden();
            $this->getJson("/api/v1/leave-requests/{$outsiderLeave}")->assertForbidden();
            $this->putJson("/api/v1/leave-requests/{$colleagueLeave}", ['reason' => 'Diubah orang lain'])->assertNotFound();
            $this->deleteJson("/api/v1/leave-requests/{$colleagueLeave}")->assertNotFound();
            $this->deleteJson("/api/v1/overtime-requests/{$colleagueOvertime}")->assertNotFound();
            $this->getJson('/api/v1/leave-requests')->assertOk()->assertJsonPath('pagination.total', 0);

            $pending = $this->getJson('/api/v1/approvals/pending')->assertOk();
            foreach (['leave_requests', 'overtime_requests', 'attendance_corrections', 'geofence_attendances', 'resignations'] as $key) {
                $this->assertSame([], $pending->json($key), "{$key} must be empty for a worker");
            }

            $this->putJson("/api/v1/approvals/leave/{$colleagueLeave}/process", ['action' => 'approved'])->assertStatus(422);
            $this->putJson("/api/v1/approvals/overtime/{$colleagueOvertime}/process", ['action' => 'rejected', 'rejection_note' => 'Tidak'])->assertStatus(422);
        }

        $this->assertSame('pending', LeaveRequest::findOrFail($colleagueLeave)->status);
        $this->assertSame('pending', OvertimeRequest::findOrFail($colleagueOvertime)->status);
        $this->assertSame('Sakit', LeaveRequest::findOrFail($colleagueLeave)->reason);
    }

    public function test_worker_cannot_use_team_client_or_master_data_endpoints(): void
    {
        foreach ([$this->employee, $this->intern, $this->freelancer] as $worker) {
            $this->actAs($worker);

            $this->getJson('/api/v1/team/roster')->assertForbidden();
            $this->getJson('/api/v1/team/week')->assertForbidden();
            $this->getJson('/api/v1/team/freelancers')->assertForbidden();
            $this->postJson('/api/v1/team/freelancers', ['full_name' => 'Freelancer Baru'])->assertForbidden();
            $this->putJson('/api/v1/team/schedules', [])->assertForbidden();
            $this->getJson('/api/v1/client/overview')->assertForbidden();
            $this->putJson("/api/v1/client/companies/{$this->company->id}/policy", ['require_gps' => false])->assertForbidden();
            $this->postJson('/api/v1/client/accounts', ['name' => 'Admin Palsu', 'email' => 'palsu@example.test', 'roles' => ['Admin']])->assertForbidden();
            $this->getJson('/api/v1/master/employees')->assertForbidden();
            $this->getJson('/api/v1/master/users')->assertForbidden();
            $this->getJson('/api/v1/shield/roles')->assertForbidden();
        }

        $this->assertFalse(User::where('email', 'palsu@example.test')->exists());
    }

    public function test_worker_web_panel_only_offers_self_service_pages(): void
    {
        foreach ([$this->employee, $this->intern, $this->freelancer] as $worker) {
            $this->actingAs(User::findOrFail($worker->user_id));

            $this->assertTrue(AbsenHariIni::canAccess(), 'Presensi Mandiri must be open to workers');
            $this->assertTrue(LeaveRequestResource::canViewAny(), 'Pengajuan cuti must be open to workers');

            $this->assertFalse(ApprovalSaya::canAccess());
            $this->assertFalse(JadwalMingguan::canAccess());
            $this->assertFalse(EmployeeResource::canViewAny());
            $this->assertFalse(AttendanceResource::canViewAny());
            $this->assertFalse(ShiftScheduleResource::canViewAny());
            $this->assertFalse(UserResource::canViewAny());
            $this->assertFalse(CompanyResource::canViewAny());
            $this->assertFalse(TenantResource::canViewAny());

            $this->get(EmployeeResource::getUrl('index'))->assertForbidden();
            $this->get(UserResource::getUrl('index'))->assertForbidden();
        }
    }

    // ── Account lifecycle ────────────────────────────────────────────────────

    public function test_ended_contract_or_resigned_worker_cannot_check_in(): void
    {
        $this->setPolicy(['require_gps' => false]);

        $this->employee->update(['status' => 'resigned']);
        $this->checkIn($this->employee)->assertForbidden()->assertJsonPath('message', 'Status kerja Anda tidak aktif. Hubungi koordinator atau HR.');

        $token = User::findOrFail($this->freelancer->user_id)->createToken('auth-token')->plainTextToken;
        app(WorkerAccountService::class)->endContract($this->freelancer->fresh(), today());
        $this->app['auth']->forgetGuards();

        $this->assertSame(0, User::findOrFail($this->freelancer->user_id)->tokens()->count());
        $this->withToken($token)->postJson('/api/v1/attendance/check-in')->assertUnauthorized();
        $this->signOutEverywhere();
        $this->postJson('/api/v1/auth/login', ['email' => $this->freelancer->user->email, 'password' => 'password'])->assertForbidden();

        $this->assertSame(0, Attendance::count());
    }

    public function test_contract_past_its_end_date_cannot_check_in_before_the_nightly_close(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $expired = $this->makeFreelancer($this->company, $this->outlet, ['end_date' => '2026-11-15', 'status' => 'active']);

        // The nightly workers:close-expired-contracts has not run (or failed): the end date alone must block.
        $this->checkIn($expired)->assertForbidden();
        $this->assertSame(0, Attendance::where('freelancer_id', $expired->id)->count());
    }

    public function test_inactive_klien_cannot_sign_in_and_open_sessions_end(): void
    {
        $this->setPolicy(['require_gps' => false]);
        $email = User::findOrFail($this->employee->user_id)->email;

        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password'])->assertOk()->json('token');
        $this->signOutEverywhere();
        $this->withToken($token)->getJson('/api/v1/attendance/today')->assertOk();

        $this->tenant->update(['is_active' => false]);
        $this->assertSame(0, User::findOrFail($this->employee->user_id)->tokens()->count());
        $this->signOutEverywhere();

        $this->withToken($token)->getJson('/api/v1/attendance/today')->assertUnauthorized();
        $this->signOutEverywhere();
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun Anda tidak aktif. Hubungi admin untuk mengaktifkannya kembali.');

        // A worker of another Klien is unaffected.
        $this->signOutEverywhere();
        $this->postJson('/api/v1/auth/login', ['email' => User::findOrFail($this->outsider->user_id)->email, 'password' => 'password'])->assertOk();
    }
}
