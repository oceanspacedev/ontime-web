<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Filament\Pages\AbsenHariIni;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\AttendanceCheckService;
use App\Services\GeofenceService;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QA probe for QA-STAGING.md MOD-001..MOD-008 and ATT-001..ATT-024, run locally against the API
 * (and the web presensi page where the case compares channels). Each test asserts the oracle of the case.
 */
class ATTAProbeTest extends TestCase
{
    use RefreshDatabase;

    private const HQ_LAT = -6.2000000;

    private const HQ_LNG = 106.8166000;

    private const A1_LAT = -6.2100000;

    private const A1_LNG = 106.8166000;

    private const A2_LAT = -6.2200000;

    private const A2_LNG = 106.8166000;

    private const A3_LAT = -6.2300000;

    private const A3_LNG = 106.8166000;

    private const FAR_LAT = -6.3000000;

    private const FAR_LNG = 106.9000000;

    private const CHECK_IN_URL = '/api/v1/attendance/check-in';

    private const CHECK_OUT_URL = '/api/v1/attendance/check-out';

    private const GOOD_POSES = [
        'front' => ['yaw' => 3.0, 'pitch' => 2.0],
        'left' => ['yaw' => 31.0, 'pitch' => -4.0],
        'right' => ['yaw' => -29.0, 'pitch' => 5.0],
    ];

    /** @var list<array<string, mixed>> */
    private array $detectQueue = [];

    private float $similarity = 0.95;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        Notification::fake();
        Queue::fake();

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

        $this->company = Company::create([
            'name' => 'PT QA Probe',
            'code' => 'QAP',
            'latitude' => self::HQ_LAT,
            'longitude' => self::HQ_LNG,
            'is_active' => true,
        ]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);

        $this->setMode(false, 'none');
        $this->travelTo(today()->setTime(8, 0));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function compreFaceResponse(Request $request): PromiseInterface
    {
        if (str_contains($request->url(), '/api/v1/detection/detect')) {
            $answer = array_shift($this->detectQueue) ?? self::GOOD_POSES['front'];

            return Http::response([
                'result' => [['box' => ['probability' => 0.99], 'pose' => $answer + ['roll' => 0.0]]],
            ]);
        }

        if (str_contains($request->url(), '/api/v1/verification/verify')) {
            return Http::response(['result' => [['face_matches' => [['similarity' => $this->similarity]]]]]);
        }

        return Http::response(['message' => 'Not found'], 404);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function setMode(bool $gps, string $selfieMode, array $extra = []): void
    {
        $this->company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode($selfieMode),
            'require_gps' => $gps,
            'geofence_radius_meters' => 100,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
            ...$extra,
        ]);

        if (in_array($selfieMode, ['face', 'liveness'], true)) {
            config(['services.face.driver' => 'compreface']);
        }
    }

    private function makeEmployee(): Employee
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'EMP'.$user->id,
            'full_name' => 'Pegawai '.$user->id,
            'join_date' => now()->subYear(),
            'status' => 'permanent',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeIntern(array $attributes = []): Intern
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Intern::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'NIS'.$user->id,
            'full_name' => 'Magang '.$user->id,
            'institution' => 'Universitas Uji',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeFreelancer(array $attributes = [], bool $scheduled = true): Freelancer
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $freelancer = Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'Freelance '.$user->id,
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
            ...$attributes,
        ]);

        // A freelancer without a schedule checks in off-schedule (approval and a cover shift), so the
        // mode probes give them today's 08:00-17:00 shift with no outlet: HQ and branches stay valid.
        if ($scheduled) {
            ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => today(), 'shift_id' => $this->dayShift()->id]);
        }

        return $freelancer;
    }

    private function dayShift(): Shift
    {
        return Shift::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'QA-P'],
            ['name' => 'Pagi QA', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'late_tolerance_minutes' => 15, 'is_active' => true],
        );
    }

    private function makeWorker(string $type): Employee|Intern|Freelancer
    {
        return match ($type) {
            'employee' => $this->makeEmployee(),
            'intern' => $this->makeIntern(),
            default => $this->makeFreelancer(),
        };
    }

    private function registerMasterFace(Employee|Intern|Freelancer $worker): void
    {
        $path = UploadedFile::fake()->image('master.jpg', 80, 80)->store('master-faces', 'public');
        $worker->update(['master_face_photo' => $path, 'master_face_verified_at' => now()]);
    }

    private function actAs(Employee|Intern|Freelancer $worker): User
    {
        $user = User::findOrFail($worker->user_id);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submit(Employee|Intern|Freelancer $worker, string $url, array $data = []): TestResponse
    {
        $this->actAs($worker);

        return $this->post($url, $data, ['Accept' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(Employee|Intern|Freelancer $worker, bool $gps, string $selfieMode, string $purpose, float $lat = self::HQ_LAT, float $lng = self::HQ_LNG): array
    {
        $payload = $gps ? ['latitude' => $lat, 'longitude' => $lng, 'accuracy' => 8.5, 'is_mocked' => '0'] : [];

        if (in_array($selfieMode, ['photo', 'face'], true)) {
            $payload['photo'] = UploadedFile::fake()->image('selfie.jpg', 80, 80);
        }

        if ($selfieMode === 'liveness') {
            $this->actAs($worker);
            $challenge = $this->postJson('/api/v1/attendance/liveness-challenge', ['purpose' => $purpose])->assertOk()->json();

            foreach ($challenge['steps'] as $step) {
                $this->detectQueue[] = self::GOOD_POSES[$step['key']];
            }

            $payload['liveness_challenge_id'] = $challenge['challenge_id'];
            $payload['liveness_frames'] = array_map(
                fn (int $index): UploadedFile => UploadedFile::fake()->image("frame-{$index}.jpg", 80, 80),
                range(1, count($challenge['steps'])),
            );
        }

        return $payload;
    }

    private function workerColumn(Employee|Intern|Freelancer $worker): string
    {
        return match (true) {
            $worker instanceof Employee => 'employee_id',
            $worker instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
    }

    private function rowsOf(Employee|Intern|Freelancer $worker): int
    {
        return Attendance::where($this->workerColumn($worker), $worker->id)->count();
    }

    private function todayRow(Employee|Intern|Freelancer $worker): ?Attendance
    {
        return Attendance::where($this->workerColumn($worker), $worker->id)->whereDate('date', today())->first();
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

    private function location(string $name, float $lat, float $lng, bool $active = true): CompanyLocation
    {
        return CompanyLocation::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_meters' => 100,
            'is_active' => $active,
        ]);
    }

    private function approver(): User
    {
        $approver = User::factory()->create();
        $approver->assignRole('Approver');
        $this->grantDataAccessTo($approver, $this->company->id, $this->division->id);

        return $approver->fresh();
    }

    /**
     * Latitude north of HQ at the given haversine distance in meters (along the meridian).
     */
    private function latAtDistance(float $meters): float
    {
        return self::HQ_LAT + rad2deg($meters / 6371000);
    }

    // ── MOD-001..MOD-008: every mode for Intern and Freelancer ─────────────

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function modesForInternAndFreelancer(): array
    {
        $modes = [
            'MOD-001 M0' => [false, 'none'],
            'MOD-002 M1' => [true, 'none'],
            'MOD-003 M2' => [false, 'photo'],
            'MOD-004 M3' => [true, 'photo'],
            'MOD-005 M4' => [false, 'face'],
            'MOD-006 M5' => [true, 'face'],
            'MOD-007 M6' => [false, 'liveness'],
            'MOD-008 M7' => [true, 'liveness'],
        ];

        $cases = [];

        foreach (['intern', 'freelancer'] as $type) {
            foreach ($modes as $label => [$gps, $selfie]) {
                $cases["{$label} {$type}"] = [$type, $gps, $selfie];
            }
        }

        return $cases;
    }

    /**
     * @see MOD-001 MOD-002 MOD-003 MOD-004 MOD-005 MOD-006 MOD-007 MOD-008 ATT-001
     */
    #[DataProvider('modesForInternAndFreelancer')]
    public function test_mo_d_001_to_008_every_mode_for_intern_and_freelancer_at_0800_and_1700(string $type, bool $gps, string $selfieMode): void
    {
        $this->setMode($gps, $selfieMode);
        $worker = $this->makeWorker($type);

        if (in_array($selfieMode, ['face', 'liveness'], true)) {
            $this->registerMasterFace($worker);
        }

        $this->submit($worker, self::CHECK_IN_URL, $this->payloadFor($worker, $gps, $selfieMode, 'check_in'))
            ->assertOk()
            ->assertJsonPath('requires_approval', false)
            ->assertJsonPath('attendance.status', AttendanceStatus::OnTime->value);

        $this->travelTo(today()->setTime(17, 0));

        $this->submit($worker, self::CHECK_OUT_URL, $this->payloadFor($worker, $gps, $selfieMode, 'check_out'))
            ->assertOk()
            ->assertJsonPath('requires_approval', false);

        $this->assertSame(1, $this->rowsOf($worker), 'Exactly one attendance row.');
        $row = $this->todayRow($worker);
        $this->assertSame(AttendanceStatus::OnTime, $row->status);
        $this->assertSame(0, (int) $row->late_minutes);
        $this->assertSame('08:00', $row->check_in->format('H:i'));
        $this->assertSame('17:00', $row->check_out->format('H:i'));
        $this->assertCount(0, $row->approvalSteps);
        $this->assertSame($gps, $row->check_in_lat !== null);
        $this->assertSame($selfieMode !== 'none', $row->check_in_photo !== null);
        $this->assertSame($selfieMode !== 'none', $row->check_out_photo !== null);

        // History and detail match the stored row.
        $this->actAs($worker);
        $this->getJson('/api/v1/attendances')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $row->id);
        $this->getJson("/api/v1/attendances/{$row->id}")->assertOk();
    }

    // ── ATT-003: required evidence cannot be dropped ───────────────────────

    /**
     * @see ATT-003
     */
    public function test_at_t_003_missing_required_evidence_is_422_and_leaves_no_row_or_file(): void
    {
        $this->setMode(true, 'photo');
        $worker = $this->makeFreelancer();

        $this->submit($worker, self::CHECK_IN_URL, ['longitude' => self::HQ_LNG, 'photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->submit($worker, self::CHECK_IN_URL, ['latitude' => self::HQ_LAT, 'longitude' => '', 'photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertStatus(422)->assertJsonValidationErrors('latitude');
        $this->submit($worker, self::CHECK_IN_URL, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG])
            ->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertSame(0, $this->rowsOf($worker));
        $this->assertSame([], $this->storedAttendanceFiles());
        $this->assertDatabaseCount('approval_request_steps', 0);
    }

    // ── ATT-004: check-out before check-in ─────────────────────────────────

    /**
     * @see ATT-004
     */
    public function test_at_t_004_check_out_before_check_in_is_422_without_record_or_file(): void
    {
        $this->setMode(true, 'photo');

        foreach (['employee', 'intern', 'freelancer'] as $type) {
            $worker = $this->makeWorker($type);

            $this->submit($worker, self::CHECK_OUT_URL, $this->payloadFor($worker, true, 'photo', 'check_out'))
                ->assertStatus(422)
                ->assertJsonPath('message', 'Anda belum melakukan check in hari ini.');

            $this->assertSame(0, $this->rowsOf($worker), "No row for {$type}.");
        }

        $this->assertSame([], $this->storedAttendanceFiles());
    }

    // ── ATT-005 / ATT-009: repeated submit ─────────────────────────────────

    /**
     * @see ATT-005
     * @see ATT-009
     */
    public function test_at_t_005_009_repeated_check_in_and_out_are_409_and_keep_the_original_evidence(): void
    {
        $this->setMode(true, 'photo');
        $worker = $this->makeIntern();

        $this->submit($worker, self::CHECK_IN_URL, $this->payloadFor($worker, true, 'photo', 'check_in'))->assertOk();
        $first = $this->todayRow($worker);

        $this->travelTo(today()->setTime(8, 5));
        $this->submit($worker, self::CHECK_IN_URL, $this->payloadFor($worker, true, 'photo', 'check_in', self::FAR_LAT, self::FAR_LNG))
            ->assertStatus(409);

        $this->travelTo(today()->setTime(17, 0));
        $this->submit($worker, self::CHECK_OUT_URL, $this->payloadFor($worker, true, 'photo', 'check_out'))->assertOk();
        $afterOut = $this->todayRow($worker);

        $this->travelTo(today()->setTime(17, 3));
        $this->submit($worker, self::CHECK_OUT_URL, $this->payloadFor($worker, true, 'photo', 'check_out', self::FAR_LAT, self::FAR_LNG))
            ->assertStatus(409);

        $final = $this->todayRow($worker);
        $this->assertSame(1, $this->rowsOf($worker));
        $this->assertTrue($final->check_in->equalTo($first->check_in));
        $this->assertSame($first->check_in_photo, $final->check_in_photo);
        $this->assertTrue($final->check_out->equalTo($afterOut->check_out));
        $this->assertSame($afterOut->check_out_photo, $final->check_out_photo);
        $this->assertSame(AttendanceStatus::OnTime, $final->status);
        $this->assertFalse((bool) $final->is_out_of_bounds);
        $this->assertCount(0, $final->approvalSteps);
        $this->assertCount(2, $this->storedAttendanceFiles(), 'Photos of refused repeats are removed.');
    }

    // ── ATT-010: same numeric id in the three worker tables ────────────────

    /**
     * @see ATT-010
     */
    public function test_at_t_010_same_numeric_id_in_three_worker_tables_stays_isolated(): void
    {
        $employee = $this->makeEmployee();
        $intern = $this->makeIntern();
        $freelancer = $this->makeFreelancer();
        $this->assertSame([1, 1, 1], [(int) $employee->id, (int) $intern->id, (int) $freelancer->id]);

        $ids = [];

        foreach ([$employee, $intern, $freelancer] as $worker) {
            $ids[] = $this->submit($worker, self::CHECK_IN_URL)->assertOk()->json('attendance.id');
        }

        $this->assertCount(3, array_unique($ids));
        $this->assertDatabaseCount('attendances', 3);

        foreach ([$employee, $intern, $freelancer] as $index => $worker) {
            $this->actAs($worker);
            $this->getJson('/api/v1/attendances')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[$index]);
            $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonCount(1, 'attendances')->assertJsonPath('attendance.id', $ids[$index]);

            foreach ($ids as $otherIndex => $otherId) {
                if ($otherIndex !== $index) {
                    $this->getJson("/api/v1/attendances/{$otherId}")->assertForbidden();
                }
            }
        }

        // A second check-in of each is still a 409 on its own row only.
        $this->submit($intern, self::CHECK_IN_URL)->assertStatus(409);
        $this->assertDatabaseCount('attendances', 3);
    }

    // ── ATT-011: ended worker ──────────────────────────────────────────────

    /**
     * @see ATT-011
     */
    public function test_at_t_011_ended_worker_and_contract_past_end_date_are_403_without_files(): void
    {
        $this->setMode(false, 'photo');
        $resigned = $this->makeIntern(['status' => 'completed']);
        $expired = $this->makeFreelancer(['end_date' => today()->subDay()]);

        foreach ([$resigned, $expired] as $worker) {
            $this->submit($worker, self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('a.jpg')])->assertStatus(403);
            $this->assertSame(0, $this->rowsOf($worker));
        }

        $noProfile = User::factory()->create();
        Sanctum::actingAs($noProfile);
        $this->post(self::CHECK_IN_URL, ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame([], $this->storedAttendanceFiles());
        $this->assertDatabaseCount('attendances', 0);
    }

    // ── ATT-012: contract ends today ───────────────────────────────────────

    /**
     * @see ATT-012
     */
    public function test_at_t_012_contract_ending_today_still_checks_in_on_its_last_day(): void
    {
        $worker = $this->makeFreelancer(['end_date' => today()]);

        $this->submit($worker, self::CHECK_IN_URL)->assertOk();
        $this->travelTo(today()->setTime(17, 0));
        $this->submit($worker, self::CHECK_OUT_URL)->assertOk();

        $this->assertNotNull($this->todayRow($worker)->check_out);
    }

    // ── ATT-013: inclusive radius with 2-decimal rounding ──────────────────

    /**
     * @see ATT-013
     */
    public function test_at_t_013_geofence_boundary_is_inclusive_after_two_decimal_rounding(): void
    {
        $this->setMode(true, 'none');

        $cases = [
            ['meters' => 99.99, 'valid' => true],
            ['meters' => 100.00, 'valid' => true],
            ['meters' => 100.004, 'valid' => true],
            ['meters' => 100.01, 'valid' => false],
        ];

        foreach ($cases as $case) {
            $lat = $this->latAtDistance($case['meters']);
            $this->assertSame(round($case['meters'], 2), GeofenceService::calculateDistance(self::HQ_LAT, self::HQ_LNG, $lat, self::HQ_LNG));

            $worker = $this->makeEmployee();
            $this->submit($worker, self::CHECK_IN_URL, ['latitude' => sprintf('%.12F', $lat), 'longitude' => sprintf('%.7F', self::HQ_LNG)])
                ->assertOk()
                ->assertJsonPath('requires_approval', ! $case['valid'])
                ->assertJsonPath('attendance.status', $case['valid'] ? AttendanceStatus::OnTime->value : AttendanceStatus::PendingApproval->value);
        }
    }

    // ── ATT-014: HQ and active branches without a scheduled outlet ─────────

    /**
     * @see ATT-014
     */
    public function test_at_t_014_hq_and_active_branches_are_accepted_but_inactive_branch_is_not(): void
    {
        $this->setMode(true, 'none');
        $this->location('A1', self::A1_LAT, self::A1_LNG);
        $this->location('A2', self::A2_LAT, self::A2_LNG);
        $this->location('A3', self::A3_LAT, self::A3_LNG, active: false);

        foreach ([[self::HQ_LAT, self::HQ_LNG, true], [self::A1_LAT, self::A1_LNG, true], [self::A2_LAT, self::A2_LNG, true], [self::A3_LAT, self::A3_LNG, false]] as [$lat, $lng, $valid]) {
            $worker = $this->makeFreelancer();
            $response = $this->submit($worker, self::CHECK_IN_URL, ['latitude' => $lat, 'longitude' => $lng])
                ->assertOk()
                ->assertJsonPath('requires_approval', ! $valid);

            if (! $valid) {
                $response->assertJsonPath('approval_reasons', ['di luar area kantor']);
            }
        }
    }

    // ── ATT-015: scheduled outlet restricts the geofence ───────────────────

    /**
     * @see ATT-015
     */
    public function test_at_t_015_scheduled_outlet_refuses_hq_and_other_branch_and_accepts_the_outlet(): void
    {
        $this->setMode(true, 'none');
        $a1 = $this->location('A1', self::A1_LAT, self::A1_LNG);
        $this->location('A2', self::A2_LAT, self::A2_LNG);
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);

        foreach ([[self::HQ_LAT, self::HQ_LNG, false], [self::A2_LAT, self::A2_LNG, false], [self::A1_LAT, self::A1_LNG, true]] as [$lat, $lng, $valid]) {
            $worker = $this->makeIntern();
            ShiftSchedule::create(['intern_id' => $worker->id, 'date' => today(), 'shift_id' => $shift->id, 'company_location_id' => $a1->id]);

            $response = $this->submit($worker, self::CHECK_IN_URL, ['latitude' => $lat, 'longitude' => $lng])
                ->assertOk()
                ->assertJsonPath('requires_approval', ! $valid);

            if (! $valid) {
                $response->assertJsonPath('approval_reasons', ['di luar area outlet jadwal (A1)']);
            }

            $this->assertSame($a1->id, (int) $this->todayRow($worker)->company_location_id);
        }
    }

    // ── ATT-016: roster changed after check-in ─────────────────────────────

    /**
     * @see ATT-016
     */
    public function test_at_t_016_roster_moved_after_check_in_keeps_the_check_in_outlet_for_check_out(): void
    {
        $this->setMode(true, 'none');
        $a1 = $this->location('A1', self::A1_LAT, self::A1_LNG);
        $a2 = $this->location('A2', self::A2_LAT, self::A2_LNG);
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'late_tolerance_minutes' => 15, 'is_active' => true]);

        $atA1 = $this->makeFreelancer(scheduled: false);
        $atA2 = $this->makeFreelancer(scheduled: false);

        foreach ([$atA1, $atA2] as $worker) {
            $schedule = ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => today(), 'shift_id' => $shift->id, 'company_location_id' => $a1->id]);
            $this->submit($worker, self::CHECK_IN_URL, ['latitude' => self::A1_LAT, 'longitude' => self::A1_LNG])->assertOk()->assertJsonPath('requires_approval', false);
            $schedule->update(['company_location_id' => $a2->id]);
        }

        $this->travelTo(today()->setTime(17, 0));

        $this->actAs($atA1);
        $this->getJson('/api/v1/attendance/today')->assertOk()->assertJsonPath('check_out_outlet.id', $a1->id);

        $this->submit($atA1, self::CHECK_OUT_URL, ['latitude' => self::A1_LAT, 'longitude' => self::A1_LNG])
            ->assertOk()->assertJsonPath('requires_approval', false);
        $this->submit($atA2, self::CHECK_OUT_URL, ['latitude' => self::A2_LAT, 'longitude' => self::A2_LNG])
            ->assertOk()->assertJsonPath('requires_approval', true)
            ->assertJsonPath('approval_reasons', ['di luar area outlet jadwal (A1)']);

        $this->assertSame($a1->id, (int) $this->todayRow($atA2)->company_location_id);
    }

    // ── ATT-020: coordinate domain ─────────────────────────────────────────

    /**
     * @see ATT-020
     */
    public function test_at_t_020_api_rejects_out_of_range_and_text_coordinates_and_accepts_the_domain_edges(): void
    {
        $this->setMode(true, 'none');

        foreach ([['latitude' => 90.0000001, 'longitude' => 0], ['latitude' => -91, 'longitude' => 0], ['latitude' => 0, 'longitude' => 180.5], ['latitude' => 'abc', 'longitude' => 106.8]] as $payload) {
            $worker = $this->makeEmployee();
            $this->submit($worker, self::CHECK_IN_URL, $payload)->assertStatus(422);
            $this->assertSame(0, $this->rowsOf($worker));
        }

        foreach ([['latitude' => 90, 'longitude' => 180], ['latitude' => -90, 'longitude' => -180], ['latitude' => '0', 'longitude' => '0']] as $payload) {
            $worker = $this->makeEmployee();
            $this->submit($worker, self::CHECK_IN_URL, $payload)->assertOk()->assertJsonPath('requires_approval', true);
            $row = $this->todayRow($worker);
            $this->assertEqualsWithDelta((float) $payload['latitude'], (float) $row->check_in_lat, 0.0000001);
        }
    }

    /**
     * Policy must be the same on both channels: the web presensi page accepts any float for the
     * coordinates and stores an impossible position (latitude 95) as evidence instead of refusing it.
     *
     * @see ATT-020
     * @see ATT-001
     */
    public function test_at_t_020_web_check_in_refuses_out_of_range_coordinates_like_the_api(): void
    {
        $this->setMode(true, 'none');
        $worker = $this->makeEmployee();

        $this->actingAs($worker->user->fresh());

        Livewire::test(AbsenHariIni::class)
            ->call('processCheckIn', null, 95.0, 200.0)
            ->assertReturned(fn (array $result): bool => $result['ok'] === false && $result['status'] === 422);

        $this->assertSame(0, $this->rowsOf($worker), 'An impossible coordinate must not be stored as check-in evidence.');
    }

    // ── ATT-024: three reasons, one round, lateness after approval ─────────

    /**
     * @see ATT-024
     */
    public function test_at_t_024_three_reasons_one_round_and_late_after_approval(): void
    {
        $this->setMode(true, 'face', ['mock_location_action' => 'approval', 'face_fail_action' => 'approval']);
        $this->similarity = 0.10;
        $worker = $this->makeEmployee();
        $this->registerMasterFace($worker);
        $approver = $this->approver();

        $this->travelTo(today()->setTime(8, 30));

        $this->submit($worker, self::CHECK_IN_URL, [
            'latitude' => self::FAR_LAT,
            'longitude' => self::FAR_LNG,
            'is_mocked' => 'true',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80),
        ])
            ->assertOk()
            ->assertJsonPath('approval_reasons', ['lokasi palsu terdeteksi', 'di luar area kantor', 'verifikasi wajah tidak cocok'])
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value);

        $this->travelTo(today()->setTime(17, 0));
        $this->submit($worker, self::CHECK_OUT_URL, [
            'latitude' => self::FAR_LAT,
            'longitude' => self::FAR_LNG,
            'is_mocked' => 'true',
            'photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80),
        ])->assertOk()->assertJsonPath('requires_approval', true);

        $row = $this->todayRow($worker);
        $this->assertSame(1, $this->rowsOf($worker));
        $this->assertCount(1, $row->approvalSteps, 'Check-out while check-in is pending reuses the open round.');

        app(ApprovalFlowService::class)->approveStep($row, $approver);

        $row->refresh();
        $this->assertSame(AttendanceStatus::Late, $row->status);
        $this->assertSame(30, (int) $row->late_minutes);
    }

    /**
     * A check-in whose approval was rejected must not turn into a valid attendance just because the
     * later check-out (outside the geofence) gets approved: the new round only judges the check-out.
     *
     * @see ATT-024
     * @see ATT-005
     */
    public function test_at_t_024_approving_the_check_out_round_does_not_revive_a_rejected_check_in(): void
    {
        $this->setMode(true, 'none', ['mock_location_action' => 'approval']);
        $worker = $this->makeEmployee();
        $approver = $this->approver();
        $service = app(ApprovalFlowService::class);

        $this->submit($worker, self::CHECK_IN_URL, ['latitude' => self::HQ_LAT, 'longitude' => self::HQ_LNG, 'is_mocked' => '1'])
            ->assertOk()->assertJsonPath('requires_approval', true);

        $service->rejectStep($this->todayRow($worker), $approver, 'Lokasi palsu, tidak sah.');
        $this->assertSame(AttendanceStatus::Rejected, $this->todayRow($worker)->status);

        $this->travelTo(today()->setTime(17, 0));
        // Decision: a check-out on a rejected attendance is refused, so no new round can revive it.
        $this->submit($worker, self::CHECK_OUT_URL, ['latitude' => self::FAR_LAT, 'longitude' => self::FAR_LNG])
            ->assertStatus(409)
            ->assertJsonPath('message', AttendanceCheckService::REJECTED_CHECK_OUT_MESSAGE);

        $row = $this->todayRow($worker);
        $this->assertSame(AttendanceStatus::Rejected, $row->status, 'A check-out revived a check-in the approver had rejected.');
        $this->assertNull($row->check_out);
    }
}
