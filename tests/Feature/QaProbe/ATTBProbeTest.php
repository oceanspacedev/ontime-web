<?php

namespace Tests\Feature\QaProbe;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QA-STAGING.md catalog ATT-025..ATT-045 (photo/token, face, lateness, roster, night shift, segments)
 * executed against the API. Cases already covered by sibling tests are not repeated here.
 */
class ATTBProbeTest extends TestCase
{
    use RefreshDatabase;

    private const D = '2026-11-16';

    private const D1 = '2026-11-17';

    private float $similarity = 0.95;

    private ?int $faceServiceStatus = null;

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
            'services.whatsapp_gateway.url' => null,
            'services.onesignal.app_id' => null,
        ]);

        Notification::fake();
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake(fn (Request $request): PromiseInterface => $this->compreFaceResponse($request));

        $this->travelTo(Carbon::parse(self::D.' 07:00:00'));
    }

    private function compreFaceResponse(Request $request): PromiseInterface
    {
        if ($this->faceServiceStatus !== null) {
            return Http::response(['message' => 'Internal error'], $this->faceServiceStatus);
        }

        if (str_contains($request->url(), '/api/v1/verification/verify')) {
            return Http::response(['result' => [['face_matches' => [['similarity' => $this->similarity]]]]]);
        }

        return Http::response(['message' => 'Not found'], 404);
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function employee(array $policy = [], ?Shift $defaultShift = null, string $selfieMode = 'none'): Employee
    {
        $company = Company::create([
            'name' => 'PT Probe '.fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->lexify('?????')),
            'latitude' => -6.2,
            'longitude' => 106.8166,
            'is_active' => true,
        ]);
        $company->policy->update([
            ...CompanyPolicy::flagsForSelfieMode($selfieMode),
            'require_gps' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
            ...$policy,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Employee');
        $division = Division::create(['company_id' => $company->id, 'name' => 'OPS', 'code' => 'OPS'.$company->id, 'is_active' => true]);

        $employee = Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Kasir'])->id,
            'nip' => 'EMP'.$user->id,
            'full_name' => 'Pekerja Probe',
            'join_date' => '2026-01-01',
            'status' => 'permanent',
        ]);

        if ($defaultShift) {
            $defaultShift->update(['company_id' => $company->id]);
            $employee->update(['shift_id' => $defaultShift->id]);
        }

        return $employee->fresh();
    }

    private function shift(array $attributes): Shift
    {
        $company = Company::create(['name' => 'PT Shift '.fake()->unique()->word(), 'code' => strtoupper(fake()->unique()->lexify('?????')), 'is_active' => true]);

        return Shift::create(['company_id' => $company->id, 'name' => 'S', 'code' => 'S'.fake()->unique()->numberBetween(1, 9999), 'is_active' => true, ...$attributes]);
    }

    private function actAs(Employee $employee): void
    {
        Sanctum::actingAs(User::findOrFail($employee->user_id));
    }

    private function at(string $dateTime): void
    {
        $this->travelTo(Carbon::parse($dateTime));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkIn(Employee $employee, array $data = []): TestResponse
    {
        $this->actAs($employee);

        return $this->post('/api/v1/attendance/check-in', $data, ['Accept' => 'application/json']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkOut(Employee $employee, array $data = []): TestResponse
    {
        $this->actAs($employee);

        return $this->post('/api/v1/attendance/check-out', $data, ['Accept' => 'application/json']);
    }

    private function registerMasterFace(Employee $employee): void
    {
        $path = UploadedFile::fake()->image('master.jpg', 80, 80)->store('master-faces', 'public');
        $employee->update(['master_face_photo' => $path, 'master_face_verified_at' => now()]);
    }

    // ── ATT-025: API photo size and content ──────────────────────────────────

    /**
     * @see ATT-025
     */
    public function test_att_025_api_photo_over_5120_kb_or_not_an_image_is_422_without_row_or_orphan_file(): void
    {
        $employee = $this->employee(selfieMode: 'photo');

        $this->checkIn($employee, ['photo' => UploadedFile::fake()->image('big.jpg', 80, 80)->size(5121)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        // A real (not faked) upload, so the MIME type is read from the bytes as in production, not from the name.
        $textFile = tempnam(sys_get_temp_dir(), 'att025');
        file_put_contents($textFile, 'just text, not a picture');
        $this->checkIn($employee, ['photo' => new UploadedFile($textFile, 'fake.jpg', null, null, true)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->assertSame(0, Attendance::where('employee_id', $employee->id)->count());
        $this->assertSame([], Storage::disk('public')->allFiles('attendance'));

        $this->checkIn($employee, ['photo' => UploadedFile::fake()->image('ok.webp', 80, 80)->size(5120)])->assertOk();
        $this->assertCount(1, Storage::disk('public')->allFiles('attendance/photos'));
    }

    // ── ATT-030: face threshold boundary ─────────────────────────────────────

    /**
     * @see ATT-030
     */
    public function test_att_030_face_match_threshold_is_inclusive_and_the_score_is_kept(): void
    {
        config(['services.face.driver' => 'compreface']);

        foreach ([[0.59, false], [0.60, true], [0.61, true]] as [$similarity, $expectedMatch]) {
            $this->similarity = $similarity;
            $employee = $this->employee(['face_match_threshold' => 60, 'face_fail_action' => 'approval'], selfieMode: 'face');
            $this->registerMasterFace($employee);

            $this->checkIn($employee, ['photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)])->assertOk();

            $row = Attendance::where('employee_id', $employee->id)->firstOrFail();
            $this->assertSame($expectedMatch, (bool) $row->is_face_verified, "similarity {$similarity}");
            $this->assertSame((int) round($similarity * 100), (int) $row->face_match_score);
            $this->assertStringContainsString('Skor: '.(int) round($similarity * 100), (string) $row->face_verification_notes);
            $this->assertSame($expectedMatch ? AttendanceStatus::OnTime : AttendanceStatus::PendingApproval, $row->status);
        }
    }

    // ── ATT-031: face service down on check-out ──────────────────────────────

    /**
     * @see ATT-031
     */
    public function test_att_031_face_service_down_on_check_out_is_503_and_leaves_the_row_open_without_orphan_photo(): void
    {
        config(['services.face.driver' => 'compreface']);
        $employee = $this->employee(selfieMode: 'face');
        $this->registerMasterFace($employee);

        $this->checkIn($employee, ['photo' => UploadedFile::fake()->image('in.jpg', 80, 80)])->assertOk();
        $filesAfterCheckIn = Storage::disk('public')->allFiles('attendance/photos');

        $this->at(self::D.' 17:00:00');
        $this->faceServiceStatus = 500;

        $this->checkOut($employee, ['photo' => UploadedFile::fake()->image('out.jpg', 80, 80)])->assertStatus(503);

        $row = Attendance::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($row->check_out);
        $this->assertNull($row->check_out_photo);
        $this->assertSame($filesAfterCheckIn, Storage::disk('public')->allFiles('attendance/photos'));

        $this->faceServiceStatus = null;
        $this->checkOut($employee, ['photo' => UploadedFile::fake()->image('out.jpg', 80, 80)])->assertOk();
        $this->assertNotNull($row->fresh()->check_out);
    }

    // ── ATT-037: lateness to the second ──────────────────────────────────────

    /**
     * @see ATT-037
     */
    public function test_att_037_lateness_boundaries_to_the_second(): void
    {
        $cases = [
            '07:59:00' => [0, AttendanceStatus::OnTime],
            '08:00:00' => [0, AttendanceStatus::OnTime],
            '08:15:00' => [0, AttendanceStatus::OnTime],
            '08:15:01' => [15, AttendanceStatus::Late],
            '08:16:00' => [16, AttendanceStatus::Late],
        ];

        foreach ($cases as $time => [$late, $status]) {
            $employee = $this->employee();
            $this->at(self::D.' '.$time);

            $this->checkIn($employee)->assertOk();

            $row = Attendance::where('employee_id', $employee->id)->firstOrFail();
            $this->assertSame($late, (int) $row->late_minutes, $time);
            $this->assertSame($status, $row->status, $time);
        }
    }

    // ── ATT-039 / ATT-040: night shift and the 20-hour window ────────────────

    /**
     * @see ATT-039
     */
    public function test_att_039_night_shift_stays_on_the_check_in_date_and_counts_480_minutes(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '22:00', 'end_time' => '06:00']));

        $this->at(self::D.' 22:00:00');
        $rowId = $this->checkIn($employee)->assertOk()->json('attendance.id');

        $this->at(self::D1.' 00:01:00');
        $this->actAs($employee);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('has_checked_out', false)
            ->assertJsonPath('attendance.id', $rowId);
        $this->checkIn($employee)->assertStatus(409);

        $this->at(self::D1.' 06:00:00');
        $this->checkOut($employee)->assertOk()->assertJsonPath('attendance.id', $rowId);

        $row = Attendance::findOrFail($rowId);
        $this->assertSame(self::D, $row->date->toDateString());
        $this->assertSame(480, $row->workedMinutes());
        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
    }

    /**
     * @see ATT-040
     */
    public function test_att_040_yesterdays_open_row_is_eligible_at_exactly_20_hours(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '22:00', 'end_time' => '06:00']));
        $this->at(self::D.' 22:00:00');
        $rowId = $this->checkIn($employee)->assertOk()->json('attendance.id');

        $this->at(self::D1.' 18:00:00');
        $this->checkIn($employee)->assertStatus(409);
        $this->checkOut($employee)->assertOk()->assertJsonPath('attendance.id', $rowId);
    }

    /**
     * @see ATT-040
     */
    public function test_att_040_one_second_past_20_hours_does_not_attach_to_yesterday(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '22:00', 'end_time' => '06:00']));
        $this->at(self::D.' 22:00:00');
        $rowId = $this->checkIn($employee)->assertOk()->json('attendance.id');

        $this->at(self::D1.' 18:00:01');
        $this->checkOut($employee)->assertStatus(422);
        $this->assertNull(Attendance::findOrFail($rowId)->check_out);

        // The D1 shift starts 22:00; check-in opens 60 minutes before it.
        $this->at(self::D1.' 21:00:01');
        $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.date', self::D1);
        $this->assertNull(Attendance::findOrFail($rowId)->check_out);
    }

    // ── ATT-041: forgotten day-shift check-out ───────────────────────────────

    /**
     * @see ATT-041
     */
    /**
     * Decision (approved in the "QA Jadwal Freelance dan Absensi" session): a forgotten check-out of a
     * shift that does not cross midnight is not closed the next morning; it goes through a correction.
     * The forgotten day must still not block today.
     */
    public function test_att_041_forgotten_day_check_out_is_closable_then_today_works_normally(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '14:00', 'end_time' => '22:00']));
        $this->at(self::D.' 13:55:00');
        $yesterdayId = $this->checkIn($employee)->assertOk()->json('attendance.id');

        $this->at(self::D1.' 07:00:00');
        $this->checkOut($employee)->assertStatus(422)->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Ajukan koreksi'));
        $this->assertNull(Attendance::findOrFail($yesterdayId)->check_out);

        $this->at(self::D1.' 13:50:00');
        $todayId = $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.date', self::D1)->json('attendance.id');

        $this->at(self::D1.' 22:00:00');
        $this->checkOut($employee)->assertOk()->assertJsonPath('attendance.id', $todayId);
        $this->assertSame(2, Attendance::where('employee_id', $employee->id)->count());
    }

    // ── ATT-042 / ATT-043: split shift segments ──────────────────────────────

    /**
     * @see ATT-042
     */
    public function test_att_042_second_segment_opens_at_16_45_00_and_is_judged_from_17_45(): void
    {
        $split = ['start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00'];
        $employee = $this->employee(defaultShift: $this->shift($split));

        $this->at(self::D.' 07:45:00');
        $this->checkIn($employee)->assertOk();
        $this->at(self::D.' 13:00:00');
        $this->checkOut($employee)->assertOk();

        $this->at(self::D.' 16:44:59');
        $this->checkIn($employee)->assertStatus(422);
        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());

        $this->at(self::D.' 16:45:00');
        $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.segment', 2);
        $this->assertSame(0, (int) Attendance::where('employee_id', $employee->id)->where('segment', 2)->value('late_minutes'));

        $late = $this->employee(defaultShift: $this->shift($split));
        $this->at(self::D.' 07:45:00');
        $this->checkIn($late)->assertOk();
        $this->at(self::D.' 13:00:00');
        $this->checkOut($late)->assertOk();
        $this->at(self::D.' 18:00:01');
        $this->checkIn($late)->assertOk();
        $this->assertSame(15, (int) Attendance::where('employee_id', $late->id)->where('segment', 2)->value('late_minutes'));
    }

    /**
     * @see ATT-043
     */
    public function test_att_043_no_second_segment_while_first_open_and_no_third_segment(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']));

        $this->at(self::D.' 07:45:00');
        $this->checkIn($employee)->assertOk();
        $this->at(self::D.' 17:45:00');
        $this->checkIn($employee)->assertStatus(409);
        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->count());
        $this->assertNull(Attendance::where('employee_id', $employee->id)->value('check_out'));

        $this->checkOut($employee)->assertOk();
        $this->checkIn($employee)->assertOk();
        $this->at(self::D.' 22:00:00');
        $this->checkOut($employee)->assertOk();
        $this->checkIn($employee)->assertStatus(409);
        $this->assertSame(2, Attendance::where('employee_id', $employee->id)->count());
    }

    // ── ATT-045: first night-shift check-in after midnight ───────────────────

    /**
     * Whatever business date the 00:30 check-in is given (section 6 leaves it open), the worker's own
     * night shift of D+1 that starts at 22:00 is a separate shift and must still be checkable-in.
     *
     * @see ATT-045
     */
    public function test_att_045_late_after_midnight_check_in_does_not_block_the_next_night_shift(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '22:00', 'end_time' => '06:00']));

        $this->at(self::D1.' 00:30:00');
        $this->checkIn($employee)->assertOk();
        $this->at(self::D1.' 06:00:00');
        $this->checkOut($employee)->assertOk();

        $this->at(self::D1.' 21:55:00');
        $this->checkIn($employee)->assertOk();
    }

    // ── Beyond the written steps: split shift whose second segment ends after midnight ──

    /**
     * The shift form allows a second segment that ends after midnight (18:00 - 00:30) and
     * Shift::scheduledMinutes() counts it as overnight. After midnight its open row must be handled like
     * a night shift: offered for check-out on Today, and a new check-in refused until it is closed.
     *
     * @see ATT-039
     */
    public function test_att_039_split_shift_second_segment_past_midnight_is_still_open_after_midnight(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '10:00', 'end_time' => '14:00', 'second_start_time' => '18:00', 'second_end_time' => '00:30']));

        $this->at(self::D.' 10:00:00');
        $this->checkIn($employee)->assertOk();
        $this->at(self::D.' 14:00:00');
        $this->checkOut($employee)->assertOk();
        $this->at(self::D.' 18:00:00');
        $segmentTwoId = $this->checkIn($employee)->assertOk()->json('attendance.id');

        $this->at(self::D1.' 00:20:00');
        $this->actAs($employee);
        $this->getJson('/api/v1/attendance/today')
            ->assertOk()
            ->assertJsonPath('has_checked_in', true)
            ->assertJsonPath('attendance.id', $segmentTwoId);

        $this->checkIn($employee)->assertStatus(409);
        $this->assertSame(2, Attendance::where('employee_id', $employee->id)->count());
    }

    /**
     * Same shift as above: tapping "check in" after midnight (what Today offers) must not open a new
     * day while segment 2 of D is still open, otherwise D's segment 2 never closes and D+1's real
     * 10:00 check-in is refused as "already checked in".
     *
     * @see ATT-039
     */
    public function test_att_039_split_shift_past_midnight_refuses_a_new_day_until_segment_two_is_closed(): void
    {
        $employee = $this->employee(defaultShift: $this->shift(['start_time' => '10:00', 'end_time' => '14:00', 'second_start_time' => '18:00', 'second_end_time' => '00:30']));

        $this->at(self::D.' 10:00:00');
        $this->checkIn($employee)->assertOk();
        $this->at(self::D.' 14:00:00');
        $this->checkOut($employee)->assertOk();
        $this->at(self::D.' 18:00:00');
        $segmentTwoId = $this->checkIn($employee)->assertOk()->json('attendance.id');

        $this->at(self::D1.' 00:20:00');
        $this->checkIn($employee)->assertStatus(409);

        $this->at(self::D1.' 00:30:00');
        $this->checkOut($employee)->assertOk()->assertJsonPath('attendance.id', $segmentTwoId);

        $this->at(self::D1.' 10:00:00');
        $this->checkIn($employee)->assertOk()->assertJsonPath('attendance.date', self::D1);
    }
}
