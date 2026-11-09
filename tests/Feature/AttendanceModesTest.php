<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\LivenessService;
use App\Support\SelfieUploadToken;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * End-to-end coverage of every attendance mode through the mobile API: GPS (geofence and mock
 * location) on or off, and a selfie mode of none, photo, face or liveness.
 *
 * CompreFace is faked over HTTP: detection answers come from a queue filled per test in step
 * order, verification answers with the configured similarity.
 */
class AttendanceModesTest extends TestCase
{
    use RefreshDatabase;

    private const HQ_LAT = -6.1753924;

    private const HQ_LNG = 106.8271528;

    private const OUTSIDE_LAT = -6.2000000;

    private const OUTSIDE_LNG = 106.8500000;

    private const CHECK_IN_URL = '/api/v1/attendance/check-in';

    private const CHECK_OUT_URL = '/api/v1/attendance/check-out';

    private const CHALLENGE_URL = '/api/v1/attendance/liveness-challenge';

    private const MOCK_REJECTED = 'Lokasi palsu (mock location) terdeteksi. Matikan aplikasi pemalsu lokasi lalu coba lagi.';

    private const MASTER_MISSING = 'Foto Master Wajah belum didaftarkan. Silakan daftarkan Foto Master Wajah Anda terlebih dahulu.';

    private const LIVENESS_REQUIRED = 'Verifikasi liveness wajib. Ambil foto sesuai langkah yang diminta.';

    private const FACE_SERVICE_DOWN = 'Layanan verifikasi wajah sedang tidak tersedia. Coba lagi beberapa saat.';

    /**
     * Poses CompreFace reports for a correct frame of each step, in degrees.
     *
     * @var array<string, array{yaw: float, pitch: float}>
     */
    private const GOOD_POSES = [
        'front' => ['yaw' => 3.0, 'pitch' => 2.0],
        'left' => ['yaw' => 31.0, 'pitch' => -4.0],
        'right' => ['yaw' => -29.0, 'pitch' => 5.0],
    ];

    /**
     * Answers for the next CompreFace detection calls: a pose (optionally with "faces") or a raw
     * "status" + "body" response.
     *
     * @var list<array<string, mixed>>
     */
    private array $detectQueue = [];

    private float $similarity = 0.95;

    private ?int $faceServiceStatus = null;

    private bool $faceServiceUnreachable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'filesystems.default' => 'public',
            'services.face.driver' => 'basic',
            'services.face.compreface.url' => 'http://compreface.test',
            'services.face.compreface.verification_key' => 'verify-key',
            'services.face.compreface.detection_key' => 'detect-key',
            'services.face.liveness.ttl_seconds' => 180,
            'services.face.liveness.front_max_yaw' => 15,
            'services.face.liveness.side_min_yaw' => 20,
            'services.face.liveness.max_pitch' => 25,
        ]);

        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake(fn (Request $request): PromiseInterface => $this->compreFaceResponse($request));

        // Before the 08:00 start so every successful check-in is on time.
        $this->travelTo(today()->setTime(7, 30));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function compreFaceResponse(Request $request): PromiseInterface
    {
        if ($this->faceServiceUnreachable) {
            return (Http::failedConnection())($request);
        }

        if ($this->faceServiceStatus !== null) {
            return Http::response(['message' => 'Internal error'], $this->faceServiceStatus);
        }

        if (str_contains($request->url(), '/api/v1/detection/detect')) {
            $answer = array_shift($this->detectQueue);

            if ($answer === null) {
                $this->fail('Unexpected CompreFace detection call: no detection answer queued.');
            }

            if (isset($answer['status'])) {
                return Http::response($answer['body'] ?? [], $answer['status']);
            }

            $faces = $answer['faces'] ?? 1;
            unset($answer['faces']);

            return Http::response([
                'result' => array_fill(0, $faces, ['box' => ['probability' => 0.99], 'pose' => $answer + ['roll' => 0.0]]),
            ]);
        }

        if (str_contains($request->url(), '/api/v1/verification/verify')) {
            return Http::response(['result' => [['face_matches' => [['similarity' => $this->similarity]]]]]);
        }

        return Http::response(['message' => 'Not found'], 404);
    }

    private function useCompreFace(float $similarity = 0.95): void
    {
        config(['services.face.driver' => 'compreface']);
        $this->similarity = $similarity;
    }

    private function createCompany(): Company
    {
        return Company::create([
            'name' => 'PT Mode '.fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->lexify('????')),
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
            'is_active' => true,
        ]);
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
            'full_name' => 'Pegawai Uji',
            'join_date' => now(),
            'status' => 'permanent',
        ]);
    }

    /**
     * @param  'none'|'photo'|'face'|'liveness'  $selfieMode
     * @param  array<string, mixed>  $extra
     */
    private function employeeWithMode(bool $gps, string $selfieMode, array $extra = []): Employee
    {
        $company = $this->createCompany();
        $company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode($selfieMode),
            'require_gps' => $gps,
            'geofence_radius_meters' => 100,
            'work_start_time' => '08:00:00',
            'late_tolerance_minutes' => 15,
            ...$extra,
        ]);

        return $this->createEmployee($company);
    }

    private function registerMasterFace(Employee $employee): string
    {
        $masterPath = UploadedFile::fake()->image('master.jpg', 80, 80)->store('master-faces', 'public');
        $employee->update(['master_face_photo' => $masterPath, 'master_face_verified_at' => now()]);

        return $masterPath;
    }

    /**
     * Authenticate a freshly loaded user so relations (company policy) are never stale between requests.
     */
    private function actAs(Employee $employee): User
    {
        $user = User::findOrFail($employee->user_id);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Multipart request like the mobile app sends it (booleans as "1"/"0").
     *
     * @param  array<string, mixed>  $data
     */
    private function submit(Employee $employee, string $url, array $data = []): TestResponse
    {
        $this->actAs($employee);

        return $this->post($url, $data, ['Accept' => 'application/json']);
    }

    /**
     * @return array{challenge_id: string, steps: list<array{key: string, label: string, instruction: string}>, expires_at: string, expires_in: int}
     */
    private function issueChallenge(Employee $employee, string $purpose = 'check_in'): array
    {
        $this->actAs($employee);

        return $this->postJson(self::CHALLENGE_URL, ['purpose' => $purpose])->assertOk()->json();
    }

    /**
     * Queue one detection answer per challenge step, in step order.
     *
     * @param  list<array{key: string}>  $steps
     * @param  array<string, array<string, mixed>>  $overrides  per step key
     */
    private function queuePoses(array $steps, array $overrides = []): void
    {
        foreach ($steps as $step) {
            $this->detectQueue[] = $overrides[$step['key']] ?? self::GOOD_POSES[$step['key']];
        }
    }

    /**
     * @param  array{challenge_id: string, steps: list<array{key: string}>}  $challenge
     * @return array{liveness_challenge_id: string, liveness_frames: list<UploadedFile>}
     */
    private function livenessPayload(array $challenge, ?int $frameCount = null): array
    {
        return [
            'liveness_challenge_id' => $challenge['challenge_id'],
            'liveness_frames' => array_map(
                fn (int $index): UploadedFile => UploadedFile::fake()->image("frame-{$index}.jpg", 80, 80),
                range(1, $frameCount ?? count($challenge['steps'])),
            ),
        ];
    }

    /**
     * Everything the mobile app sends for a mode, with good poses queued for liveness.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(Employee $employee, bool $gps, string $selfieMode, string $purpose): array
    {
        $payload = $gps ? ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'accuracy' => 8.5, 'is_mocked' => '0'] : [];

        if (in_array($selfieMode, ['photo', 'face'], true)) {
            $payload['photo'] = UploadedFile::fake()->image('selfie.jpg', 80, 80);
        }

        if ($selfieMode === 'liveness') {
            $challenge = $this->issueChallenge($employee, $purpose);
            $this->queuePoses($challenge['steps']);
            $payload = [...$payload, ...$this->livenessPayload($challenge)];
        }

        return $payload;
    }

    private function todayAttendance(Employee $employee): ?Attendance
    {
        return Attendance::where('employee_id', $employee->id)->whereDate('date', today())->first();
    }

    /**
     * @return list<string>
     */
    private function storedAttendanceFiles(): array
    {
        return [
            ...Storage::disk('public')->allFiles('attendance/photos'),
            ...Storage::disk('public')->allFiles('attendance/liveness'),
        ];
    }

    // ── All 8 modes ──────────────────────────────────────────────────────────

    /**
     * @return array<string, array{bool, string, string}>
     */
    public static function modes(): array
    {
        return [
            'klik saja' => [false, 'none', 'Klik saja'],
            'gps saja' => [true, 'none', 'GPS'],
            'foto saja' => [false, 'photo', 'Foto'],
            'gps + foto' => [true, 'photo', 'GPS + Foto'],
            'foto + wajah' => [false, 'face', 'Foto + Wajah'],
            'gps + wajah' => [true, 'face', 'GPS + Foto + Wajah'],
            'liveness' => [false, 'liveness', 'Liveness + Wajah'],
            'gps + liveness' => [true, 'liveness', 'GPS + Liveness + Wajah'],
        ];
    }

    #[DataProvider('modes')]
    public function test_every_mode_checks_in_and_out_through_the_api(bool $gps, string $selfieMode, string $label): void
    {
        $employee = $this->employeeWithMode($gps, $selfieMode);

        if (in_array($selfieMode, ['face', 'liveness'], true)) {
            $this->useCompreFace();
            $this->registerMasterFace($employee);
        }

        $this->actAs($employee);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.company.policy.selfie_mode', $selfieMode)
            ->assertJsonPath('user.employee.company.policy.require_gps', $gps)
            ->assertJsonPath('user.employee.company.policy.mode_label', $label);

        $this->submit($employee, self::CHECK_IN_URL, $this->payloadFor($employee, $gps, $selfieMode, 'check_in'))
            ->assertOk()
            ->assertJsonPath('message', 'Check in berhasil')
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('approval_reasons', [])
            ->assertJsonPath('attendance.status', AttendanceStatus::OnTime->value)
            ->assertJsonPath('attendance.is_mock_location', false)
            ->assertJsonPath('attendance.is_out_of_bounds', false);

        $this->submit($employee, self::CHECK_OUT_URL, $this->payloadFor($employee, $gps, $selfieMode, 'check_out'))
            ->assertOk()
            ->assertJsonPath('message', 'Check out berhasil')
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('approval_reasons', []);

        $attendance = $this->todayAttendance($employee);
        $this->assertNotNull($attendance);
        $this->assertNotNull($attendance->check_in);
        $this->assertNotNull($attendance->check_out);
        $this->assertSame(AttendanceStatus::OnTime, $attendance->status);
        $this->assertCount(0, $attendance->approvalSteps);

        if ($gps) {
            $this->assertEqualsWithDelta(self::HQ_LAT, (float) $attendance->check_in_lat, 0.000001);
            $this->assertEqualsWithDelta(self::HQ_LNG, (float) $attendance->check_out_lng, 0.000001);
        } else {
            $this->assertNull($attendance->check_in_lat);
        }

        if ($selfieMode === 'none') {
            $this->assertNull($attendance->check_in_photo);
            $this->assertNull($attendance->check_out_photo);
        } else {
            Storage::disk('public')->assertExists($attendance->check_in_photo);
            Storage::disk('public')->assertExists($attendance->check_out_photo);
        }

        if (in_array($selfieMode, ['face', 'liveness'], true)) {
            $this->assertTrue($attendance->is_face_verified);
            $this->assertSame(95, $attendance->face_match_score);
        } else {
            $this->assertNull($attendance->is_face_verified);
        }

        if ($selfieMode === 'liveness') {
            $this->assertTrue($attendance->liveness_verified);
            $this->assertSame('Liveness terverifikasi: gerakan kepala sesuai instruksi.', $attendance->liveness_notes);
            $this->assertCount(3, $attendance->liveness_frames);
        } else {
            $this->assertNull($attendance->liveness_verified);
            $this->assertNull($attendance->liveness_frames);
        }

        if (in_array($selfieMode, ['none', 'photo'], true)) {
            Http::assertNothingSent();
        }
    }

    public function test_click_only_mode_needs_no_location_and_no_photo(): void
    {
        $employee = $this->employeeWithMode(false, 'none');

        $this->submit($employee, self::CHECK_IN_URL)
            ->assertOk()
            ->assertJsonPath('message', 'Check in berhasil')
            ->assertJsonPath('attendance.check_in_lat', null)
            ->assertJsonPath('attendance.check_in_photo', null);
    }

    public function test_face_mode_works_with_the_basic_driver(): void
    {
        $employee = $this->employeeWithMode(false, 'face');
        $this->registerMasterFace($employee);

        $this->submit($employee, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertOk()
            ->assertJsonPath('attendance.is_face_verified', true);

        Http::assertNothingSent();
    }

    // ── GPS and mock location ────────────────────────────────────────────────

    public function test_gps_mode_requires_coordinates(): void
    {
        $employee = $this->employeeWithMode(true, 'none');

        $this->submit($employee, self::CHECK_IN_URL)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Lokasi GPS wajib diisi.')
            ->assertJsonPath('errors.latitude.0', 'Lokasi GPS wajib diisi.');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_check_in_outside_the_geofence_goes_to_approval_with_its_reason(): void
    {
        $employee = $this->employeeWithMode(true, 'none');

        $this->submit($employee, self::CHECK_IN_URL, ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons', ['di luar area kantor'])
            ->assertJsonPath('message', 'Check in berhasil dicatat dan sedang menunggu persetujuan atasan karena di luar area kantor.')
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value)
            ->assertJsonPath('attendance.is_out_of_bounds', true)
            ->assertJsonPath('attendance.late_minutes', 0);

        $attendance = $this->todayAttendance($employee);
        $this->assertCount(1, $attendance->approvalSteps);
    }

    public function test_check_out_outside_the_geofence_goes_to_approval_with_its_reason(): void
    {
        $employee = $this->employeeWithMode(true, 'none');

        $this->submit($employee, self::CHECK_IN_URL, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG])->assertOk();

        $this->submit($employee, self::CHECK_OUT_URL, ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons', ['di luar area kantor'])
            ->assertJsonPath('message', 'Check out berhasil dicatat dan sedang menunggu persetujuan atasan karena di luar area kantor.')
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value)
            ->assertJsonPath('attendance.is_out_of_bounds', true);
    }

    public function test_mock_location_is_rejected_when_the_policy_says_reject(): void
    {
        $employee = $this->employeeWithMode(true, 'photo', ['mock_location_action' => 'reject']);

        $this->submit($employee, self::CHECK_IN_URL, [
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
            'is_mocked' => '1',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80),
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', self::MOCK_REJECTED)
            ->assertJsonPath('errors.latitude.0', self::MOCK_REJECTED);

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], $this->storedAttendanceFiles(), 'The selfie of a refused attempt must be deleted.');
    }

    public function test_mock_location_defaults_to_reject(): void
    {
        $employee = $this->employeeWithMode(true, 'none');
        $this->assertSame('reject', $employee->company->policy->fresh()->mock_location_action);

        $this->submit($employee, self::CHECK_IN_URL, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => 'true'])
            ->assertStatus(422)
            ->assertJsonPath('message', self::MOCK_REJECTED);
    }

    public function test_mock_location_goes_to_approval_when_the_policy_says_approval(): void
    {
        $employee = $this->employeeWithMode(true, 'none', ['mock_location_action' => 'approval']);

        $this->submit($employee, self::CHECK_IN_URL, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => '1'])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons', ['lokasi palsu terdeteksi'])
            ->assertJsonPath('message', 'Check in berhasil dicatat dan sedang menunggu persetujuan atasan karena lokasi palsu terdeteksi.')
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value)
            ->assertJsonPath('attendance.is_mock_location', true)
            ->assertJsonPath('attendance.is_out_of_bounds', false);

        $this->assertCount(1, $this->todayAttendance($employee)->approvalSteps);
    }

    public function test_mock_location_and_outside_geofence_list_both_reasons(): void
    {
        $employee = $this->employeeWithMode(true, 'none', ['mock_location_action' => 'approval']);

        $this->submit($employee, self::CHECK_IN_URL, ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG, 'is_mocked' => 'true'])
            ->assertOk()
            ->assertJsonPath('approval_reasons', ['lokasi palsu terdeteksi', 'di luar area kantor'])
            ->assertJsonPath('message', 'Check in berhasil dicatat dan sedang menunggu persetujuan atasan karena lokasi palsu terdeteksi dan di luar area kantor.');
    }

    public function test_mock_location_is_only_recorded_when_gps_is_off(): void
    {
        $employee = $this->employeeWithMode(false, 'none', ['mock_location_action' => 'reject']);

        $this->submit($employee, self::CHECK_IN_URL, ['latitude' => self::OUTSIDE_LAT, 'longitude' => self::OUTSIDE_LNG, 'is_mocked' => '1'])
            ->assertOk()
            ->assertJsonPath('message', 'Check in berhasil')
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('attendance.status', AttendanceStatus::OnTime->value)
            ->assertJsonPath('attendance.is_mock_location', true)
            ->assertJsonPath('attendance.is_out_of_bounds', false);

        $attendance = $this->todayAttendance($employee);
        $this->assertTrue($attendance->is_mock_location);
        $this->assertEqualsWithDelta(self::OUTSIDE_LAT, (float) $attendance->check_in_lat, 0.000001);
    }

    public function test_check_out_keeps_the_mock_location_flag_of_the_check_in(): void
    {
        $employee = $this->employeeWithMode(true, 'none', ['mock_location_action' => 'approval']);

        $this->submit($employee, self::CHECK_IN_URL, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => '1'])->assertOk();

        $this->submit($employee, self::CHECK_OUT_URL, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => '0'])
            ->assertOk()
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('attendance.is_mock_location', true);
    }

    public function test_is_mocked_false_text_is_not_treated_as_mocked(): void
    {
        $employee = $this->employeeWithMode(true, 'none');

        $this->submit($employee, self::CHECK_IN_URL, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => 'false', 'accuracy' => '12.5'])
            ->assertOk()
            ->assertJsonPath('attendance.is_mock_location', false);
    }

    public function test_invalid_accuracy_and_is_mocked_are_rejected_by_validation(): void
    {
        $employee = $this->employeeWithMode(true, 'none');

        $this->submit($employee, self::CHECK_IN_URL, [
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
            'accuracy' => '-1',
            'is_mocked' => 'maybe',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accuracy', 'is_mocked']);
    }

    // ── Photo and face ───────────────────────────────────────────────────────

    public function test_photo_mode_requires_a_photo(): void
    {
        $employee = $this->employeeWithMode(false, 'photo');

        $this->submit($employee, self::CHECK_IN_URL)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Foto selfie absensi wajib diunggah.')
            ->assertJsonPath('errors.photo.0', 'Foto selfie absensi wajib diunggah.');
    }

    public function test_face_mode_requires_a_photo_before_the_master_face(): void
    {
        $employee = $this->employeeWithMode(false, 'face');

        $this->submit($employee, self::CHECK_IN_URL)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Foto selfie absensi wajib diunggah.');
    }

    public function test_face_mode_requires_a_master_face(): void
    {
        $employee = $this->employeeWithMode(false, 'face');

        $this->submit($employee, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertStatus(422)
            ->assertJsonPath('message', self::MASTER_MISSING)
            ->assertJsonPath('errors.photo.0', self::MASTER_MISSING);

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], $this->storedAttendanceFiles());
    }

    public function test_face_mismatch_is_rejected_when_the_policy_says_reject(): void
    {
        $this->useCompreFace(0.3);
        $employee = $this->employeeWithMode(false, 'face', ['face_fail_action' => 'reject', 'face_match_threshold' => 60]);
        $this->registerMasterFace($employee);

        $message = 'Verifikasi wajah gagal: Tingkat kemiripan wajah rendah (Skor: 30%, Threshold minimum: 60%).';

        $this->submit($employee, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertStatus(422)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('errors.photo.0', $message);

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], $this->storedAttendanceFiles());
    }

    public function test_face_mismatch_goes_to_approval_when_the_policy_says_approval(): void
    {
        $this->useCompreFace(0.3);
        $employee = $this->employeeWithMode(false, 'face', ['face_fail_action' => 'approval']);
        $this->registerMasterFace($employee);

        $this->submit($employee, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons', ['verifikasi wajah tidak cocok'])
            ->assertJsonPath('message', 'Check in berhasil dicatat dan sedang menunggu persetujuan atasan karena verifikasi wajah tidak cocok.')
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value)
            ->assertJsonPath('attendance.is_face_verified', false)
            ->assertJsonPath('attendance.face_match_score', 30);

        $this->assertCount(1, $this->todayAttendance($employee)->approvalSteps);
    }

    public function test_all_soft_failures_are_listed_in_order(): void
    {
        $this->useCompreFace(0.3);
        $employee = $this->employeeWithMode(true, 'face', ['face_fail_action' => 'approval', 'mock_location_action' => 'approval']);
        $this->registerMasterFace($employee);

        $this->submit($employee, self::CHECK_IN_URL, [
            'latitude' => self::OUTSIDE_LAT,
            'longitude' => self::OUTSIDE_LNG,
            'is_mocked' => '1',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80),
        ])
            ->assertOk()
            ->assertJsonPath('approval_reasons', ['lokasi palsu terdeteksi', 'di luar area kantor', 'verifikasi wajah tidak cocok'])
            ->assertJsonPath('message', 'Check in berhasil dicatat dan sedang menunggu persetujuan atasan karena lokasi palsu terdeteksi dan di luar area kantor dan verifikasi wajah tidak cocok.');
    }

    public function test_face_service_errors_answer_503(): void
    {
        $this->useCompreFace();
        $this->faceServiceStatus = 500;
        $employee = $this->employeeWithMode(false, 'face');
        $this->registerMasterFace($employee);

        $this->submit($employee, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertStatus(503)
            ->assertJsonPath('message', self::FACE_SERVICE_DOWN)
            ->assertJsonPath('errors.photo.0', self::FACE_SERVICE_DOWN);

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], $this->storedAttendanceFiles());
    }

    public function test_unreachable_face_service_answers_503(): void
    {
        $this->useCompreFace();
        $this->faceServiceUnreachable = true;
        $employee = $this->employeeWithMode(false, 'face');
        $this->registerMasterFace($employee);

        $this->submit($employee, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertStatus(503)
            ->assertJsonPath('message', self::FACE_SERVICE_DOWN);
    }

    public function test_unconfigured_face_service_answers_503(): void
    {
        $this->useCompreFace();
        config(['services.face.compreface.url' => null]);
        $employee = $this->employeeWithMode(false, 'face');
        $this->registerMasterFace($employee);

        $this->submit($employee, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertStatus(503)
            ->assertJsonPath('message', self::FACE_SERVICE_DOWN);

        Http::assertNothingSent();
    }

    // ── Liveness challenge endpoint ──────────────────────────────────────────

    public function test_liveness_challenge_returns_three_steps(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->actAs($employee);

        $response = $this->postJson(self::CHALLENGE_URL, ['purpose' => 'check_in'])
            ->assertOk()
            ->assertJsonStructure(['challenge_id', 'steps' => [['key', 'label', 'instruction']], 'expires_at', 'expires_in'])
            ->assertJsonCount(3, 'steps')
            ->assertJsonPath('expires_in', 180)
            ->assertJsonPath('expires_at', now()->addSeconds(180)->toIso8601String());

        $this->assertTrue(Str::isUuid($response->json('challenge_id')));
        $steps = collect($response->json('steps'))->keyBy('key');
        $this->assertEqualsCanonicalizing(['front', 'left', 'right'], $steps->keys()->all());
        $this->assertSame('Hadap depan', $steps['front']['label']);
        $this->assertSame('Tengok ke kiri', $steps['left']['label']);
        $this->assertSame('Tengok ke kanan', $steps['right']['label']);
        $this->assertNotNull(Cache::get('liveness:'.$response->json('challenge_id')));
        Http::assertNothingSent();
    }

    public function test_liveness_challenge_is_refused_when_liveness_is_not_required(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(true, 'face');
        $this->actAs($employee);

        $this->postJson(self::CHALLENGE_URL, ['purpose' => 'check_in'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Liveness tidak diwajibkan untuk badan usaha Anda.');
    }

    public function test_liveness_challenge_answers_503_with_the_basic_driver(): void
    {
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->actAs($employee);

        $this->postJson(self::CHALLENGE_URL, ['purpose' => 'check_out'])
            ->assertStatus(503)
            ->assertJsonPath('message', LivenessService::DRIVER_MESSAGE);
    }

    public function test_liveness_challenge_validates_the_purpose(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->actAs($employee);

        $this->postJson(self::CHALLENGE_URL, [])->assertStatus(422)->assertJsonValidationErrors(['purpose']);
        $this->postJson(self::CHALLENGE_URL, ['purpose' => 'lunch'])->assertStatus(422)->assertJsonValidationErrors(['purpose']);
    }

    public function test_liveness_challenge_requires_authentication(): void
    {
        $this->postJson(self::CHALLENGE_URL, ['purpose' => 'check_in'])->assertUnauthorized();
    }

    // ── Liveness check-in ────────────────────────────────────────────────────

    public function test_liveness_check_in_requires_challenge_and_frames(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);

        $this->submit($employee, self::CHECK_IN_URL)
            ->assertStatus(422)
            ->assertJsonPath('message', self::LIVENESS_REQUIRED)
            ->assertJsonPath('errors.liveness_frames.0', self::LIVENESS_REQUIRED);

        $challenge = $this->issueChallenge($employee);

        $this->submit($employee, self::CHECK_IN_URL, ['liveness_challenge_id' => $challenge['challenge_id']])
            ->assertStatus(422)
            ->assertJsonPath('message', self::LIVENESS_REQUIRED);

        $this->submit($employee, self::CHECK_IN_URL, ['liveness_frames' => $this->livenessPayload($challenge)['liveness_frames']])
            ->assertStatus(422)
            ->assertJsonPath('message', self::LIVENESS_REQUIRED);

        $this->assertSame([], $this->storedAttendanceFiles());
        Http::assertNothingSent();
    }

    public function test_liveness_check_in_requires_a_master_face(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $challenge = $this->issueChallenge($employee);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(422)
            ->assertJsonPath('message', self::MASTER_MISSING);
    }

    public function test_liveness_success_uses_the_front_frame_as_check_in_photo(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $masterPath = $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps']);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertOk()
            ->assertJsonPath('message', 'Check in berhasil')
            ->assertJsonPath('attendance.liveness_verified', true)
            ->assertJsonPath('attendance.liveness_notes', 'Liveness terverifikasi: gerakan kepala sesuai instruksi.')
            ->assertJsonPath('attendance.is_face_verified', true)
            ->assertJsonPath('attendance.face_match_score', 95);

        $attendance = $this->todayAttendance($employee);
        $frontIndex = array_search('front', array_column($challenge['steps'], 'key'), true);

        $this->assertCount(3, $attendance->liveness_frames);
        $this->assertSame($attendance->liveness_frames[$frontIndex], $attendance->check_in_photo);
        $this->assertStringStartsWith('attendance/liveness/', $attendance->check_in_photo);

        foreach ($attendance->liveness_frames as $framePath) {
            Storage::disk('public')->assertExists($framePath);
            $this->assertStringContainsString('LIVE_IN', $framePath);
        }

        $this->assertNull(Cache::get('liveness:'.$challenge['challenge_id']), 'A challenge must be single use.');

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/verification/verify')
            && $request->hasHeader('x-api-key', 'verify-key'));
        $this->assertNotSame($masterPath, $attendance->check_in_photo);
    }

    public function test_liveness_success_deletes_an_extra_photo_the_record_does_not_use(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps']);

        $this->submit($employee, self::CHECK_IN_URL, [
            ...$this->livenessPayload($challenge),
            'photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80),
        ])->assertOk();

        $attendance = $this->todayAttendance($employee);

        $this->assertSame([], Storage::disk('public')->allFiles('attendance/photos'));
        $this->assertEqualsCanonicalizing($attendance->liveness_frames, $this->storedAttendanceFiles());
    }

    public function test_click_only_mode_keeps_an_optional_photo(): void
    {
        $employee = $this->employeeWithMode(false, 'none');

        $this->submit($employee, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertOk();

        $attendance = $this->todayAttendance($employee);
        $this->assertNotNull($attendance->check_in_photo);
        Storage::disk('public')->assertExists($attendance->check_in_photo);
    }

    public function test_liveness_with_wrong_pose_is_rejected_and_frames_are_deleted(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps'], ['front' => ['yaw' => 40.0, 'pitch' => 0.0]]);

        $message = 'Gerakan kepala tidak sesuai pada langkah "Hadap depan". Ulangi dan ikuti instruksi dengan jelas.';

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(422)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('errors.liveness_frames.0', $message);

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], $this->storedAttendanceFiles());
    }

    public function test_liveness_side_frames_turned_the_same_way_are_rejected(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps'], [
            'left' => ['yaw' => 30.0, 'pitch' => 0.0],
            'right' => ['yaw' => 30.0, 'pitch' => 0.0],
        ]);

        $sideKeys = array_values(array_filter(array_column($challenge['steps'], 'key'), fn (string $key): bool => $key !== 'front'));
        $secondSideLabel = $sideKeys[1] === 'left' ? 'Tengok ke kiri' : 'Tengok ke kanan';

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(422)
            ->assertJsonPath('message', "Gerakan kepala tidak sesuai pada langkah \"{$secondSideLabel}\". Ulangi dan ikuti instruksi dengan jelas.");
    }

    public function test_liveness_frame_without_a_face_is_rejected(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps'], ['left' => ['status' => 400, 'body' => ['code' => 28, 'message' => 'No face is found in the given image']]]);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Wajah tidak terdeteksi dengan jelas pada langkah "Tengok ke kiri".');
    }

    public function test_liveness_frame_with_two_faces_is_rejected(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps'], ['right' => [...self::GOOD_POSES['right'], 'faces' => 2]]);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Terdeteksi lebih dari satu wajah pada langkah "Tengok ke kanan".');
    }

    public function test_liveness_with_the_wrong_number_of_frames_is_rejected(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge, 2))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jumlah foto liveness tidak sesuai. Mulai ulang verifikasi.');

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($this->issueChallenge($employee), 4))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['liveness_frames']);

        Http::assertNothingSent();
    }

    public function test_expired_liveness_challenge_is_rejected(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);

        $this->travel(181)->seconds();

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(422)
            ->assertJsonPath('message', LivenessService::EXPIRED_MESSAGE)
            ->assertJsonPath('errors.liveness_frames.0', LivenessService::EXPIRED_MESSAGE);

        Http::assertNothingSent();
    }

    public function test_liveness_challenge_cannot_be_reused(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps'], ['front' => ['yaw' => 45.0, 'pitch' => 0.0]]);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))->assertStatus(422);

        $this->detectQueue = [];
        $this->queuePoses($challenge['steps']);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(422)
            ->assertJsonPath('message', LivenessService::EXPIRED_MESSAGE);

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_liveness_challenge_of_another_purpose_or_user_is_rejected(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);

        $checkOutChallenge = $this->issueChallenge($employee, 'check_out');

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($checkOutChallenge))
            ->assertStatus(422)
            ->assertJsonPath('message', LivenessService::EXPIRED_MESSAGE);

        $colleague = $this->createEmployee($employee->company);
        $colleagueChallenge = $this->issueChallenge($colleague);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($colleagueChallenge))
            ->assertStatus(422)
            ->assertJsonPath('message', LivenessService::EXPIRED_MESSAGE);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload([
            'challenge_id' => (string) Str::uuid(),
            'steps' => [['key' => 'front'], ['key' => 'left'], ['key' => 'right']],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('message', LivenessService::EXPIRED_MESSAGE);
    }

    public function test_liveness_pass_with_face_mismatch_is_rejected_when_the_policy_says_reject(): void
    {
        $this->useCompreFace(0.4);
        $employee = $this->employeeWithMode(false, 'liveness', ['face_fail_action' => 'reject', 'face_match_threshold' => 70]);
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps']);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Verifikasi wajah gagal: Tingkat kemiripan wajah rendah (Skor: 40%, Threshold minimum: 70%).')
            ->assertJsonPath('errors.photo.0', 'Verifikasi wajah gagal: Tingkat kemiripan wajah rendah (Skor: 40%, Threshold minimum: 70%).');

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], $this->storedAttendanceFiles());
    }

    public function test_liveness_pass_with_face_mismatch_goes_to_approval_when_the_policy_says_approval(): void
    {
        $this->useCompreFace(0.4);
        $employee = $this->employeeWithMode(true, 'liveness', ['face_fail_action' => 'approval']);
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->queuePoses($challenge['steps']);

        $this->submit($employee, self::CHECK_IN_URL, [
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
            ...$this->livenessPayload($challenge),
        ])
            ->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons', ['verifikasi wajah tidak cocok'])
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value)
            ->assertJsonPath('attendance.liveness_verified', true)
            ->assertJsonPath('attendance.is_face_verified', false)
            ->assertJsonPath('attendance.face_match_score', 40);

        $attendance = $this->todayAttendance($employee);
        $this->assertCount(3, $attendance->liveness_frames);
        $this->assertCount(1, $attendance->approvalSteps);
    }

    public function test_liveness_face_service_down_answers_503_and_deletes_frames(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);
        $this->faceServiceStatus = 502;

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(503)
            ->assertJsonPath('message', self::FACE_SERVICE_DOWN);

        $this->assertDatabaseCount('attendances', 0);
        $this->assertSame([], $this->storedAttendanceFiles());
    }

    public function test_liveness_check_in_answers_503_when_the_driver_was_switched_to_basic(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);
        $challenge = $this->issueChallenge($employee);

        config(['services.face.driver' => 'basic']);

        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($challenge))
            ->assertStatus(503)
            ->assertJsonPath('message', self::FACE_SERVICE_DOWN);
    }

    public function test_liveness_check_out_stores_its_frames_with_the_check_out_prefix(): void
    {
        $this->useCompreFace();
        $employee = $this->employeeWithMode(false, 'liveness');
        $this->registerMasterFace($employee);

        $inChallenge = $this->issueChallenge($employee);
        $this->queuePoses($inChallenge['steps']);
        $this->submit($employee, self::CHECK_IN_URL, $this->livenessPayload($inChallenge))->assertOk();

        $outChallenge = $this->issueChallenge($employee, 'check_out');
        $this->queuePoses($outChallenge['steps']);
        $this->submit($employee, self::CHECK_OUT_URL, $this->livenessPayload($outChallenge))
            ->assertOk()
            ->assertJsonPath('message', 'Check out berhasil');

        $attendance = $this->todayAttendance($employee);
        $frontIndex = array_search('front', array_column($outChallenge['steps'], 'key'), true);

        $this->assertSame($attendance->liveness_frames[$frontIndex], $attendance->check_out_photo);
        $this->assertStringContainsString('LIVE_OUT', $attendance->check_out_photo);
        $this->assertStringContainsString('LIVE_IN', $attendance->check_in_photo);
    }

    // ── State rules ──────────────────────────────────────────────────────────

    public function test_state_rules_keep_their_messages(): void
    {
        $employee = $this->employeeWithMode(false, 'none');

        $this->submit($employee, self::CHECK_OUT_URL)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Anda belum melakukan check in hari ini.')
            ->assertJsonPath('errors.attendance.0', 'Anda belum melakukan check in hari ini.');

        $this->submit($employee, self::CHECK_IN_URL)->assertOk();

        $this->submit($employee, self::CHECK_IN_URL)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Sudah melakukan check in hari ini.');

        $this->submit($employee, self::CHECK_OUT_URL)->assertOk();

        $this->submit($employee, self::CHECK_OUT_URL)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Sudah melakukan check out hari ini.');
    }

    public function test_user_without_a_worker_profile_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(self::CHECK_IN_URL)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Profil pengguna tidak ditemukan.');
    }

    // ── Resources ────────────────────────────────────────────────────────────

    public function test_auth_me_exposes_the_attendance_mode_policy(): void
    {
        $employee = $this->employeeWithMode(true, 'liveness', [
            'mock_location_action' => 'approval',
            'face_match_threshold' => 75,
            'face_fail_action' => 'approval',
        ]);
        $this->actAs($employee);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.employee.company.policy.require_gps', true)
            ->assertJsonPath('user.employee.company.policy.require_photo', true)
            ->assertJsonPath('user.employee.company.policy.require_face_recognition', true)
            ->assertJsonPath('user.employee.company.policy.require_liveness', true)
            ->assertJsonPath('user.employee.company.policy.selfie_mode', 'liveness')
            ->assertJsonPath('user.employee.company.policy.mock_location_action', 'approval')
            ->assertJsonPath('user.employee.company.policy.face_match_threshold', 75)
            ->assertJsonPath('user.employee.company.policy.face_fail_action', 'approval')
            ->assertJsonPath('user.employee.company.policy.mode_label', 'GPS + Liveness + Wajah');
    }

    public function test_liveness_flag_implies_face_and_photo_on_the_policy(): void
    {
        $company = $this->createCompany();
        $company->policy->update(['require_liveness' => true, 'require_face_recognition' => false, 'require_photo' => false]);

        $policy = $company->policy->fresh();
        $this->assertTrue($policy->require_face_recognition);
        $this->assertTrue($policy->require_photo);
        $this->assertSame('liveness', $policy->selfieMode());
    }

    public function test_attendance_endpoints_expose_mock_and_liveness_fields(): void
    {
        $employee = $this->employeeWithMode(false, 'none');
        $this->submit($employee, self::CHECK_IN_URL, ['is_mocked' => '1'])->assertOk();

        $this->actAs($employee);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('attendance.is_mock_location', true)
            ->assertJsonPath('attendance.liveness_verified', null)
            ->assertJsonPath('attendance.liveness_notes', null);

        $attendance = $this->todayAttendance($employee);

        $this->getJson("/api/v1/attendances/{$attendance->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['is_mock_location', 'liveness_verified', 'liveness_notes', 'is_face_verified', 'face_match_score']]);
    }

    // ── Web selfie upload ────────────────────────────────────────────────────

    public function test_web_selfie_upload_rejects_guests(): void
    {
        $this->postJson(route('upload.selfie'), ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_web_selfie_upload_rejects_api_tokens(): void
    {
        $employee = $this->employeeWithMode(false, 'photo');
        $this->actAs($employee);

        $this->postJson(route('upload.selfie'), ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertUnauthorized();
    }

    public function test_web_selfie_upload_returns_a_token_and_no_path(): void
    {
        $employee = $this->employeeWithMode(false, 'photo');
        $user = User::findOrFail($employee->user_id);

        $response = $this->actingAs($user, 'web')
            ->postJson(route('upload.selfie'), ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'token', 'url'])
            ->assertJsonMissingPath('path');

        $path = SelfieUploadToken::resolve($response->json('token'), $user);
        Storage::disk('public')->assertExists($path);
        $this->assertStringNotContainsString($path, $response->getContent());
    }

    public function test_web_selfie_upload_rejects_an_empty_upload_without_echoing_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson(route('upload.selfie'), ['photo_base64' => '%%%not-base64%%%'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('path')
            ->assertDontSee('not-base64');
    }
}
