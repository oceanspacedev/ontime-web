<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The contract between the Android app and the API for photo and GPS submissions: what the app
 * sends is accepted, and every failure answers JSON with an Indonesian message the app can show.
 */
class AndroidUploadContractTest extends TestCase
{
    use RefreshDatabase;

    private const HQ_LAT = -6.1753924;

    private const HQ_LNG = 106.8271528;

    protected function setUp(): void
    {
        parent::setUp();

        // Check-in opens 60 minutes before the start (company hours 08:00), so the clock is fixed in working hours.
        $this->travelTo(today()->setTime(8, 0));
        $this->seed(RoleSeeder::class);

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    private function createCompany(array $policy = []): Company
    {
        $company = Company::create([
            'name' => 'PT Android '.fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'is_active' => true,
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
        ]);

        $company->policy()->update(array_merge([
            'require_gps' => true,
            'require_photo' => true,
            'require_face_recognition' => false,
            'require_liveness' => false,
        ], $policy));

        return $company->fresh();
    }

    private function createEmployee(Company $company): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $division = Division::create(['company_id' => $company->id, 'name' => 'Ops', 'code' => 'OPS'.$company->id, 'is_active' => true]);
        $jobLevel = JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1]);
        $jobTitle = JobTitle::firstOrCreate(['name' => 'Crew']);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP'.$user->id,
            'full_name' => 'Crew Android',
            'join_date' => now(),
            'status' => 'permanent',
        ]);
    }

    /**
     * The multipart body the app builds in buildCheckForm(): every value is text.
     *
     * @return array<string, mixed>
     */
    private function androidCheckForm(array $overrides = []): array
    {
        return array_merge([
            'latitude' => (string) self::HQ_LAT,
            'longitude' => (string) self::HQ_LNG,
            'accuracy' => '12.5',
            'is_mocked' => '0',
            'photo' => UploadedFile::fake()->image('check-in.jpg', 960, 1280),
        ], $overrides);
    }

    private function sendMultipart(string $uri, array $form): TestResponse
    {
        return $this->post($uri, $form, ['Accept' => 'application/json']);
    }

    public function test_android_multipart_check_in_with_camera_photo_and_gps_is_accepted(): void
    {
        $employee = $this->createEmployee($this->createCompany());
        Sanctum::actingAs($employee->user);

        $this->sendMultipart('/api/v1/attendance/check-in', $this->androidCheckForm())
            ->assertOk()
            ->assertJsonPath('requires_approval', false);

        $attendance = Attendance::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($attendance->check_in_photo);
        Storage::disk('public')->assertExists($attendance->check_in_photo);
    }

    public function test_a_photo_the_server_could_not_receive_answers_in_indonesian(): void
    {
        $employee = $this->createEmployee($this->createCompany());
        Sanctum::actingAs($employee->user);

        $failed = new UploadedFile(UploadedFile::fake()->image('check-in.jpg')->getPathname(), 'check-in.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->sendMultipart('/api/v1/attendance/check-in', $this->androidCheckForm(['photo' => $failed]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.photo.0', 'Foto selfie gagal terunggah. Periksa koneksi lalu coba lagi.');
    }

    public function test_invalid_gps_values_answer_in_indonesian(): void
    {
        $employee = $this->createEmployee($this->createCompany());
        Sanctum::actingAs($employee->user);

        $this->sendMultipart('/api/v1/attendance/check-in', $this->androidCheckForm(['latitude' => '200', 'accuracy' => '-1']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.latitude.0', 'Lokasi GPS tidak valid. Perbarui lokasi lalu coba lagi.')
            ->assertJsonPath('errors.accuracy.0', 'Akurasi GPS tidak valid. Perbarui lokasi lalu coba lagi.');
    }

    public function test_a_storage_failure_answers_503_on_the_photo_and_records_nothing(): void
    {
        config([
            'filesystems.disks.broken' => ['driver' => 'local', 'root' => '/dev/null/ontime', 'throw' => false],
            'filesystems.default' => 'broken',
        ]);
        $employee = $this->createEmployee($this->createCompany());
        Sanctum::actingAs($employee->user);

        $this->sendMultipart('/api/v1/attendance/check-in', $this->androidCheckForm())
            ->assertStatus(503)
            ->assertJsonPath('errors.photo.0', 'Foto gagal disimpan di server. Coba kirim lagi beberapa saat.');

        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id]);
    }

    public function test_a_storage_failure_on_the_master_face_answers_503_json(): void
    {
        config([
            'filesystems.disks.broken' => ['driver' => 'local', 'root' => '/dev/null/ontime', 'throw' => true],
            'filesystems.default' => 'broken',
        ]);
        $employee = $this->createEmployee($this->createCompany());
        Sanctum::actingAs($employee->user);

        $this->sendMultipart('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('master-face.jpg')])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Berkas gagal disimpan di server. Coba kirim lagi beberapa saat.');

        $this->assertNull($employee->fresh()->master_face_photo);
    }

    public function test_a_body_over_the_post_limit_answers_413_json_in_indonesian(): void
    {
        $employee = $this->createEmployee($this->createCompany());
        Sanctum::actingAs($employee->user);

        $this->call('POST', '/api/v1/attendance/check-in', [], [], [], [
            'CONTENT_LENGTH' => (string) (PHP_INT_MAX >> 1),
            'HTTP_ACCEPT' => 'application/json',
        ])
            ->assertStatus(413)
            ->assertJsonPath('message', 'Ukuran foto atau lampiran terlalu besar untuk dikirim. Ambil ulang lalu coba lagi.');
    }

    public function test_leave_attachment_errors_answer_in_indonesian(): void
    {
        $employee = $this->createEmployee($this->createCompany());
        Sanctum::actingAs($employee->user);

        $this->sendMultipart('/api/v1/leave-requests', [
            'leave_type' => 'sick',
            'start_date' => today()->addDay()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'reason' => 'Sakit',
            'attachment' => UploadedFile::fake()->create('lampiran.zip', 10, 'application/zip'),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.attachment.0', 'Lampiran harus berformat JPG, PNG, PDF, atau Word.');

        $this->sendMultipart('/api/v1/leave-requests', [
            'leave_type' => 'sick',
            'start_date' => today()->addDay()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'reason' => 'Sakit',
            'attachment' => UploadedFile::fake()->create('lampiran.pdf', 6000, 'application/pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.attachment.0', 'Lampiran maksimal berukuran 5 MB.');
    }

    public function test_profile_sends_the_geofence_the_server_checks(): void
    {
        $company = $this->createCompany(['geofence_radius_meters' => 150]);
        CompanyLocation::create(['company_id' => $company->id, 'name' => 'Outlet Aktif', 'latitude' => -6.2, 'longitude' => 106.8, 'radius_meters' => null, 'is_active' => true]);
        CompanyLocation::create(['company_id' => $company->id, 'name' => 'Outlet Tutup', 'latitude' => -6.3, 'longitude' => 106.9, 'radius_meters' => 80, 'is_active' => false]);
        $employee = $this->createEmployee($company);
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.company.geofence_radius_meters', 150)
            ->assertJsonCount(1, 'user.employee.company.locations')
            ->assertJsonPath('user.employee.company.locations.0.name', 'Outlet Aktif')
            ->assertJsonPath('user.employee.company.locations.0.radius_meters', 150);
    }

    public function test_today_sends_the_outlet_check_out_is_checked_against(): void
    {
        $company = $this->createCompany(['geofence_radius_meters' => 120]);
        $outlet = CompanyLocation::create(['company_id' => $company->id, 'name' => 'Outlet Ciledug', 'latitude' => -6.23, 'longitude' => 106.71, 'radius_meters' => 60, 'is_active' => true]);
        $employee = $this->createEmployee($company);
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => now()->setTime(8, 0),
            'status' => AttendanceStatus::OnTime,
            'late_minutes' => 0,
            'company_location_id' => $outlet->id,
        ]);
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('check_out_outlet.id', $outlet->id)
            ->assertJsonPath('check_out_outlet.latitude', -6.23)
            ->assertJsonPath('check_out_outlet.longitude', 106.71)
            ->assertJsonPath('check_out_outlet.radius_meters', 60);
    }

    public function test_today_sends_no_check_out_outlet_before_check_in(): void
    {
        $employee = $this->createEmployee($this->createCompany());
        Sanctum::actingAs($employee->user);

        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('check_out_outlet', null);
    }
}
