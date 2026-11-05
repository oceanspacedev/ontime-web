<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Pages\AbsenHariIni;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Widgets\PresensiWidget;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\Face\FaceDetection;
use App\Services\Face\FaceMatch;
use App\Services\Face\FaceVerifier;
use App\Services\LivenessService;
use App\Support\SelfieUploadToken;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The web panel ("Presensi Mandiri", the policy form and the attendance list) for every attendance mode.
 */
class PresensiMandiriModesTest extends TestCase
{
    use RefreshDatabase;

    private const HQ_LAT = -6.1753924;

    private const HQ_LNG = 106.8271528;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        $this->company = Company::create([
            'name' => 'PT Mode Presensi',
            'code' => 'MODE',
            'is_active' => true,
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
        ]);
    }

    // ── Check-in with upload tokens ─────────────────────────────────────────

    public function test_photo_mode_checks_in_with_an_upload_token_from_the_selfie_endpoint(): void
    {
        $this->company->policy()->update(['require_photo' => true]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        $token = $this->postJson(route('upload.selfie'), ['photo' => UploadedFile::fake()->image('selfie.jpg', 200, 200)])
            ->assertOk()
            ->assertJsonMissingPath('path')
            ->json('token');

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', $token)
            ->assertReturned(fn (array $result): bool => $result['ok'] === true && $result['message'] === 'Check in berhasil')
            ->assertNotified('Check In Berhasil!');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNotNull($attendance->check_in_photo);
        Storage::disk('public')->assertExists($attendance->check_in_photo);
    }

    public function test_raw_storage_path_is_not_accepted_as_selfie(): void
    {
        $this->company->policy()->update(['require_photo' => true]);
        $employee = $this->makeEmployee();
        Storage::disk('public')->put('master-faces/master.jpg', 'master');
        $employee->update(['master_face_photo' => 'master-faces/master.jpg']);
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', 'master-faces/master.jpg')
            ->assertReturned(fn (array $result): bool => $result['ok'] === false
                && $result['field'] === 'photo'
                && $result['message'] === SelfieUploadToken::INVALID_MESSAGE)
            ->assertNotified('Check In Gagal');

        $this->assertDatabaseCount('attendances', 0);
        Storage::disk('public')->assertExists('master-faces/master.jpg');
    }

    public function test_upload_token_of_another_user_is_refused(): void
    {
        $this->company->policy()->update(['require_photo' => true]);
        $employee = $this->makeEmployee();
        $other = $this->makeEmployee();
        Storage::disk('public')->put('attendance-photos/other.jpg', 'selfie');

        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', SelfieUploadToken::issue('attendance-photos/other.jpg', $other->user))
            ->assertReturned(fn (array $result): bool => $result['ok'] === false && $result['field'] === 'photo');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_master_face_upload_token_cannot_be_reused_as_the_selfie(): void
    {
        $this->company->policy()->update(['require_photo' => true]);
        $employee = $this->makeEmployee();
        Storage::disk('public')->put('attendance-photos/master.jpg', 'master');
        $employee->update(['master_face_photo' => 'attendance-photos/master.jpg']);
        $this->actingAs($employee->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', SelfieUploadToken::issue('attendance-photos/master.jpg', $employee->user))
            ->assertReturned(fn (array $result): bool => $result['ok'] === false
                && $result['field'] === 'photo'
                && $result['message'] === SelfieUploadToken::INVALID_MESSAGE);

        $this->assertDatabaseCount('attendances', 0);
        Storage::disk('public')->assertExists('attendance-photos/master.jpg');
    }

    public function test_refused_check_out_with_a_reused_token_keeps_the_check_in_photo(): void
    {
        $this->company->policy()->update(['require_photo' => true]);
        $employee = $this->makeEmployee();
        Storage::disk('public')->put('attendance-photos/selfie.jpg', 'selfie');
        $token = SelfieUploadToken::issue('attendance-photos/selfie.jpg', $employee->user);
        $this->actingAs($employee->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', $token)
            ->assertReturned(fn (array $result): bool => $result['ok'] === true);

        $this->company->policy()->update(['require_gps' => true]);
        $this->actingAs($employee->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckOut', $token)
            ->assertReturned(fn (array $result): bool => $result['ok'] === false && $result['message'] === 'Lokasi GPS wajib diisi.');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('attendance-photos/selfie.jpg', $attendance->check_in_photo);
        Storage::disk('public')->assertExists('attendance-photos/selfie.jpg');
    }

    public function test_token_replayed_after_midnight_keeps_yesterdays_check_in_photo(): void
    {
        $this->company->policy()->update(['require_photo' => true]);
        $employee = $this->makeEmployee();
        Storage::disk('public')->put('attendance-photos/late-night.jpg', 'selfie');
        $this->travelTo(today()->setTime(23, 55));
        $token = SelfieUploadToken::issue('attendance-photos/late-night.jpg', $employee->user);
        $this->actingAs($employee->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', $token)
            ->assertReturned(fn (array $result): bool => $result['ok'] === true);

        $yesterday = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('attendance-photos/late-night.jpg', $yesterday->check_in_photo);

        $this->travel(10)->minutes();
        $this->company->policy()->update(['require_gps' => true]);
        $this->actingAs($employee->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', $token)
            ->assertReturned(fn (array $result): bool => $result['ok'] === false && $result['message'] === 'Lokasi GPS wajib diisi.');

        $this->assertSame('attendance-photos/late-night.jpg', $yesterday->fresh()->check_in_photo);
        Storage::disk('public')->assertExists('attendance-photos/late-night.jpg');
    }

    public function test_refused_check_in_keeps_a_master_face_another_worker_uses(): void
    {
        $this->company->policy()->update(['require_photo' => true, 'require_gps' => true]);
        $worker = $this->makeEmployee();
        $admin = $this->makeEmployee();
        Storage::disk('public')->put('master-faces/uploaded-by-admin.jpg', 'master');
        $token = SelfieUploadToken::issue('master-faces/uploaded-by-admin.jpg', $admin->user);
        $worker->update(['master_face_photo' => 'master-faces/uploaded-by-admin.jpg']);
        $this->actingAs($admin->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', $token)
            ->assertReturned(fn (array $result): bool => $result['ok'] === false && $result['message'] === 'Lokasi GPS wajib diisi.');

        Storage::disk('public')->assertExists('master-faces/uploaded-by-admin.jpg');
    }

    public function test_refused_check_in_keeps_a_liveness_frame_of_an_earlier_day(): void
    {
        $this->company->policy()->update(['require_photo' => true, 'require_gps' => true]);
        $employee = $this->makeEmployee();
        Storage::disk('public')->put('attendance-liveness/front.jpg', 'frame');
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => today()->subDay(),
            'check_in' => now()->subDay(),
            'status' => AttendanceStatus::OnTime,
            'liveness_frames' => ['attendance-liveness/front.jpg', 'attendance-liveness/left.jpg'],
        ]);
        $this->actingAs($employee->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', SelfieUploadToken::issue('attendance-liveness/front.jpg', $employee->user))
            ->assertReturned(fn (array $result): bool => $result['ok'] === false);

        Storage::disk('public')->assertExists('attendance-liveness/front.jpg');
    }

    public function test_photo_mode_without_a_photo_is_refused_inline(): void
    {
        $this->company->policy()->update(['require_photo' => true]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn')
            ->assertReturned(fn (array $result): bool => $result['message'] === 'Foto selfie absensi wajib diunggah.' && $result['status'] === 422)
            ->assertNotified('Check In Gagal');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_gps_mode_requires_coordinates_and_sends_outside_check_in_to_approval(): void
    {
        $this->company->policy()->update(['require_gps' => true, 'geofence_radius_meters' => 100]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn')
            ->assertReturned(fn (array $result): bool => $result['field'] === 'latitude' && $result['message'] === 'Lokasi GPS wajib diisi.');

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', null, -6.2, 106.85)
            ->assertReturned(fn (array $result): bool => $result['ok'] === true && $result['requires_approval'] === true)
            ->assertNotified('Check In Dikirim (Menunggu Persetujuan)');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(AttendanceStatus::PendingApproval, $attendance->status);
        $this->assertTrue($attendance->is_out_of_bounds);
    }

    public function test_click_only_mode_checks_in_and_out_without_camera_or_location(): void
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->assertSee('Klik saja')
            ->assertDontSee('x-ref="video"', false)
            ->call('processCheckIn')
            ->assertReturned(fn (array $result): bool => $result['ok'] === true);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckOut')
            ->assertReturned(fn (array $result): bool => $result['ok'] === true && $result['message'] === 'Check out berhasil')
            ->assertNotified('Check Out Berhasil!');

        $this->assertNotNull(Attendance::where('employee_id', $employee->id)->firstOrFail()->check_out);
    }

    public function test_page_renders_the_camera_and_mode_label_for_each_selfie_mode(): void
    {
        $employee = $this->makeEmployee();

        $this->company->policy()->update(['require_gps' => true, 'require_photo' => true]);
        $this->actingAs($employee->user->fresh());
        Livewire::test(AbsenHariIni::class)->assertSee('GPS + Foto')->assertSee('x-ref="video"', false);

        $this->company->policy()->update(['require_gps' => false, 'require_face_recognition' => true, 'require_photo' => true]);
        $this->actingAs($employee->user->fresh());
        Livewire::test(AbsenHariIni::class)->assertSee('Foto + Wajah')->assertSee('Foto Master Wajah Belum Terdaftar');

        $this->company->policy()->update(['require_liveness' => true]);
        $this->actingAs($employee->user->fresh());
        Livewire::test(AbsenHariIni::class)->assertSee('Liveness + Wajah')->assertSee('Verifikasi liveness');
    }

    public function test_dashboard_widget_follows_the_selfie_mode(): void
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee->user->fresh());

        Livewire::test(PresensiWidget::class)
            ->assertSee('Klik saja')
            ->assertDontSee('Foto Master Wajah belum terdaftar');

        $this->company->policy()->update(['require_gps' => true, 'require_liveness' => true]);
        $this->actingAs($employee->user->fresh());

        Livewire::test(PresensiWidget::class)
            ->assertSee('GPS + Liveness + Wajah')
            ->assertSee('Foto Master Wajah belum terdaftar');
    }

    public function test_master_face_is_registered_from_an_upload_token_only(): void
    {
        $this->company->policy()->update(['require_face_recognition' => true, 'require_photo' => true]);
        $employee = $this->makeEmployee();
        $other = $this->makeEmployee();
        Storage::disk('public')->put('attendance-photos/other.jpg', 'face');
        Storage::disk('public')->put('attendance-photos/mine.jpg', 'face');
        $this->actingAs($employee->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->callAction('updateMasterFace', data: ['master_face_photo' => 'attendance-photos/other.jpg'])
            ->assertNotified('Foto Master Wajah Gagal Disimpan');

        Livewire::test(AbsenHariIni::class)
            ->callAction('updateMasterFace', data: ['master_face_photo' => SelfieUploadToken::issue('attendance-photos/other.jpg', $other->user)])
            ->assertNotified('Foto Master Wajah Gagal Disimpan');

        $this->assertNull($employee->fresh()->master_face_photo);

        Livewire::test(AbsenHariIni::class)
            ->callAction('updateMasterFace', data: ['master_face_photo' => SelfieUploadToken::issue('attendance-photos/mine.jpg', $employee->user)])
            ->assertNotified('Foto Master Wajah Berhasil Disimpan!');

        $this->assertSame('attendance-photos/mine.jpg', $employee->fresh()->master_face_photo);
    }

    // ── Liveness ────────────────────────────────────────────────────────────

    public function test_liveness_challenge_is_refused_when_the_policy_does_not_require_it(): void
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('issueLivenessChallenge', 'check_in')
            ->assertReturned(['ok' => false, 'message' => 'Liveness tidak diwajibkan untuk badan usaha Anda.']);
    }

    public function test_liveness_challenge_explains_that_the_basic_driver_cannot_do_liveness(): void
    {
        config(['services.face.driver' => 'basic']);
        $this->company->policy()->update(['require_liveness' => true, 'require_face_recognition' => true, 'require_photo' => true]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('issueLivenessChallenge', 'check_in')
            ->assertReturned(['ok' => false, 'message' => LivenessService::DRIVER_MESSAGE]);
    }

    public function test_liveness_mode_checks_in_with_challenge_frames(): void
    {
        $this->fakePoseCapableVerifier();
        $this->company->policy()->update(['require_liveness' => true, 'require_face_recognition' => true, 'require_photo' => true]);
        $employee = $this->makeEmployee(withMasterFace: true);
        $this->actingAs($employee->user);

        $page = Livewire::test(AbsenHariIni::class)->call('issueLivenessChallenge', 'check_in');
        $challenge = data_get($page->effects, 'returns.0');

        $this->assertTrue($challenge['ok']);
        $this->assertCount(3, $challenge['steps']);

        $tokens = collect($challenge['steps'])->map(function (array $step) use ($employee): string {
            $path = "attendance-photos/{$step['key']}.jpg";
            Storage::disk('public')->put($path, 'frame');

            return SelfieUploadToken::issue($path, $employee->user);
        })->all();

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', null, null, null, $challenge['challenge_id'], $tokens)
            ->assertReturned(fn (array $result): bool => $result['ok'] === true)
            ->assertNotified('Check In Berhasil!');

        $attendance = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertTrue($attendance->liveness_verified);
        $this->assertTrue($attendance->is_face_verified);
        $this->assertSame('attendance-photos/front.jpg', $attendance->check_in_photo);
        $this->assertCount(3, $attendance->liveness_frames);
    }

    public function test_liveness_mode_without_frames_is_refused_and_flagged_for_a_restart(): void
    {
        $this->fakePoseCapableVerifier();
        $this->company->policy()->update(['require_liveness' => true, 'require_face_recognition' => true, 'require_photo' => true]);
        $employee = $this->makeEmployee(withMasterFace: true);
        $this->actingAs($employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn')
            ->assertReturned(fn (array $result): bool => $result['field'] === 'liveness_frames'
                && $result['message'] === 'Verifikasi liveness wajib. Ambil foto sesuai langkah yang diminta.')
            ->assertNotified('Verifikasi Liveness Gagal');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_refused_attempt_deletes_the_uploaded_frames(): void
    {
        $this->fakePoseCapableVerifier();
        $this->company->policy()->update(['require_liveness' => true, 'require_face_recognition' => true, 'require_photo' => true]);
        $employee = $this->makeEmployee(withMasterFace: true);
        $this->actingAs($employee->user);

        Storage::disk('public')->put('attendance-photos/front.jpg', 'frame');
        $token = SelfieUploadToken::issue('attendance-photos/front.jpg', $employee->user);

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', null, null, null, 'unknown-challenge', [$token])
            ->assertReturned(fn (array $result): bool => $result['message'] === LivenessService::EXPIRED_MESSAGE);

        Storage::disk('public')->assertMissing('attendance-photos/front.jpg');
    }

    // ── Policy form ─────────────────────────────────────────────────────────

    public function test_policy_form_shows_the_stored_selfie_mode(): void
    {
        $this->actingAsSuperadmin();
        $this->company->policy()->update(['require_face_recognition' => true, 'require_photo' => true, 'require_gps' => true]);

        Livewire::test(ListCompanies::class)
            ->assertSee('GPS + Foto + Wajah')
            ->mountAction(TestAction::make('managePolicy')->table($this->company))
            ->assertActionDataSet(['selfie_mode' => 'face', 'mock_location_action' => 'reject']);
    }

    public function test_policy_form_refuses_liveness_when_the_face_driver_cannot_read_head_pose(): void
    {
        config(['services.face.driver' => 'basic']);
        $this->actingAsSuperadmin();

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($this->company), data: $this->policyFormData(['selfie_mode' => 'liveness']))
            ->assertHasFormErrors(['selfie_mode']);

        $this->assertFalse($this->company->policy->fresh()->require_liveness);
    }

    public function test_policy_form_refuses_the_old_liveness_flag_when_the_face_driver_cannot_read_head_pose(): void
    {
        config(['services.face.driver' => 'basic']);
        $this->actingAsSuperadmin();

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($this->company), data: $this->policyFormData(['require_liveness' => true]))
            ->assertHasFormErrors(['require_liveness']);

        $this->assertFalse($this->company->policy->fresh()->require_liveness);
    }

    public function test_policy_form_accepts_the_old_liveness_flag_when_the_face_driver_reads_head_pose(): void
    {
        $this->fakePoseCapableVerifier();
        $this->actingAsSuperadmin();

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($this->company), data: $this->policyFormData(['require_liveness' => true]))
            ->assertHasNoFormErrors();

        $this->assertSame('liveness', $this->company->policy->fresh()->selfieMode());
    }

    public function test_policy_form_saves_liveness_and_mock_location_action(): void
    {
        $this->fakePoseCapableVerifier();
        $this->actingAsSuperadmin();

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($this->company), data: $this->policyFormData([
                'selfie_mode' => 'liveness',
                'require_gps' => true,
                'mock_location_action' => 'approval',
            ]))
            ->assertHasNoFormErrors();

        $policy = $this->company->policy->fresh();
        $this->assertSame([true, true, true], [$policy->require_photo, $policy->require_face_recognition, $policy->require_liveness]);
        $this->assertSame('approval', $policy->mock_location_action);
        $this->assertSame('GPS + Liveness + Wajah', $policy->modeLabel());
    }

    public function test_policy_form_can_switch_back_to_click_only(): void
    {
        $this->actingAsSuperadmin();
        $this->company->policy()->update(['require_face_recognition' => true, 'require_photo' => true]);

        Livewire::test(ListCompanies::class)
            ->callAction(TestAction::make('managePolicy')->table($this->company), data: $this->policyFormData(['selfie_mode' => 'none']))
            ->assertHasNoFormErrors();

        $this->assertSame('none', $this->company->policy->fresh()->selfieMode());
    }

    public function test_normalize_attendance_policy_still_understands_the_old_flags(): void
    {
        $this->assertSame(
            ['require_photo' => true, 'require_face_recognition' => true, 'require_liveness' => false],
            array_intersect_key(
                CompanyResource::normalizeAttendancePolicy(['require_photo' => false, 'require_face_recognition' => true]),
                array_flip(['require_photo', 'require_face_recognition', 'require_liveness']),
            ),
        );

        $normalized = CompanyResource::normalizeAttendancePolicy(['selfie_mode' => 'photo', 'require_face_recognition' => true]);
        $this->assertArrayNotHasKey('selfie_mode', $normalized);
        $this->assertFalse($normalized['require_face_recognition'], 'selfie_mode wins over old flags.');
        $this->assertTrue($normalized['require_photo']);
    }

    // ── Attendance list ─────────────────────────────────────────────────────

    public function test_attendance_list_shows_mock_location_and_verification_badges(): void
    {
        $employee = $this->makeEmployee();
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => today(),
            'check_in' => now(),
            'status' => AttendanceStatus::PendingApproval,
            'is_mock_location' => true,
            'is_face_verified' => true,
            'face_match_score' => 87,
            'liveness_verified' => true,
        ]);

        $this->actingAsSuperadmin();

        Livewire::test(ListAttendances::class)
            ->assertSee('Lokasi palsu')
            ->assertSee('Cocok (87%)')
            ->assertSee('Lolos');
    }

    private function makeEmployee(bool $withMasterFace = false): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => Division::firstOrCreate(
                ['company_id' => $this->company->id, 'name' => 'Operasional'],
                ['is_active' => true],
            )->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'MODE-'.$user->id,
            'full_name' => "Karyawan {$user->id}",
            'status' => 'active',
        ]);

        if ($withMasterFace) {
            Storage::disk('public')->put('master-faces/master-'.$employee->id.'.jpg', 'master');
            $employee->update(['master_face_photo' => 'master-faces/master-'.$employee->id.'.jpg']);
        }

        return $employee->fresh();
    }

    private function actingAsSuperadmin(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function policyFormData(array $overrides = []): array
    {
        return array_merge([
            'late_tolerance_minutes' => 15,
            'require_gps' => false,
            'geofence_radius_meters' => 100,
            'mock_location_action' => 'reject',
            'selfie_mode' => 'none',
            'face_match_threshold' => 60,
            'face_fail_action' => 'reject',
            'annual_leave_quota' => 12,
            'default_approval_stages' => 1,
        ], $overrides);
    }

    /**
     * A face service that reads the head pose from the frame's file name and always matches.
     */
    private function fakePoseCapableVerifier(): void
    {
        $this->app->instance(FaceVerifier::class, new class implements FaceVerifier
        {
            public function name(): string
            {
                return 'fake';
            }

            public function supportsPose(): bool
            {
                return true;
            }

            public function isAccurate(): bool
            {
                return true;
            }

            public function verify(string $selfiePath, string $masterPath, int $thresholdPercent): FaceMatch
            {
                return new FaceMatch(true, 92, 'Wajah cocok.');
            }

            public function detect(string $imagePath): FaceDetection
            {
                $yaw = match (true) {
                    str_contains($imagePath, 'left') => 35.0,
                    str_contains($imagePath, 'right') => -35.0,
                    default => 0.0,
                };

                return new FaceDetection(1, $yaw, 0.0, 0.0);
            }
        });
    }
}
