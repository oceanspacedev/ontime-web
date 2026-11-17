<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Pages\LaporanAbsensi;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\TeamRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real-device QA (17 Nov 2026): a freelancer on a 09:00 - 18:00 shift checked in at 01:57 and out at
 * 01:58 and the day read "Tepat Waktu". The first check-in now opens 60 minutes before the start, and
 * a check-out before the scheduled end is recorded as "pulang cepat" and shown in the reports.
 */
class AttendanceEarlyWindowAndLeaveTest extends TestCase
{
    use RefreshDatabase;

    private const TUESDAY = '2026-11-17';

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

        $this->travelTo(Carbon::parse(self::TUESDAY.' 07:00:00'));

        $tenant = Tenant::factory()->create(['name' => 'Klien QA', 'code' => 'KQ', 'is_active' => true]);
        $this->company = new Company(['name' => 'PT Outlet QA', 'code' => 'QAFL', 'is_active' => true]);
        $this->company->tenant_id = $tenant->id;
        $this->company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'code' => 'OUT', 'is_active' => true]);

        $this->company->policy()->update([
            'require_gps' => false,
            'require_photo' => false,
            'require_face_recognition' => false,
            'require_liveness' => false,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
        ]);
        $this->company->unsetRelation('policy');
    }

    private function freelancer(?Shift $scheduledShift = null): Freelancer
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole('Freelancer');

        $freelancer = Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'freelancer_number' => 'FL-'.fake()->unique()->numberBetween(1000, 9999),
            'full_name' => 'Freelance QA',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        if ($scheduledShift) {
            ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => self::TUESDAY, 'shift_id' => $scheduledShift->id]);
        }

        return $freelancer;
    }

    private function shift(string $start, string $end, ?string $secondStart = null, ?string $secondEnd = null): Shift
    {
        return Shift::create([
            'company_id' => $this->company->id,
            'name' => "Shift {$start}",
            'start_time' => $start,
            'end_time' => $end,
            'second_start_time' => $secondStart,
            'second_end_time' => $secondEnd,
            'late_tolerance_minutes' => 15,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkInAt(Freelancer $freelancer, string $time, array $data = []): TestResponse
    {
        $this->travelTo(Carbon::parse(self::TUESDAY.' '.$time));
        Sanctum::actingAs($freelancer->user);

        return $this->postJson('/api/v1/attendance/check-in', $data);
    }

    private function checkOutAt(Freelancer $freelancer, string $time): TestResponse
    {
        $this->travelTo(Carbon::parse(self::TUESDAY.' '.$time));
        Sanctum::actingAs($freelancer->user);

        return $this->postJson('/api/v1/attendance/check-out');
    }

    // ── Check-in window ──────────────────────────────────────────────────────

    public function test_check_in_hours_before_the_shift_is_refused_and_writes_nothing(): void
    {
        $freelancer = $this->freelancer($this->shift('09:00', '18:00'));

        $this->checkInAt($freelancer, '01:57:00')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shift dimulai pukul 09:00. Absen masuk dibuka pukul 08:00.');

        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_check_in_opens_exactly_sixty_minutes_before_the_start(): void
    {
        $shift = $this->shift('09:00', '18:00');

        $this->checkInAt($this->freelancer($shift), '07:59:59')->assertUnprocessable();
        $this->checkInAt($this->freelancer($shift), '08:00:00')->assertOk();
    }

    public function test_check_in_within_the_window_is_on_time_and_after_tolerance_is_late(): void
    {
        $shift = $this->shift('09:00', '18:00');
        $early = $this->freelancer($shift);
        $late = $this->freelancer($shift);

        $this->checkInAt($early, '08:30:00')->assertOk();
        $this->checkInAt($late, '09:22:00')->assertOk();

        $this->assertSame(AttendanceStatus::OnTime, Attendance::where('freelancer_id', $early->id)->sole()->status);
        $lateRow = Attendance::where('freelancer_id', $late->id)->sole();
        $this->assertSame(AttendanceStatus::Late, $lateRow->status);
        $this->assertSame(22, $lateRow->late_minutes);
    }

    /**
     * A freelancer works only when rostered: without a dated shift or a default shift the check-in is
     * "Masuk di luar jadwal", accepted at any hour and sent for approval (no company-hours window).
     */
    public function test_unscheduled_freelancer_check_in_is_off_schedule_and_waits_for_approval(): void
    {
        $early = $this->shift('02:00', '10:00');
        $freelancer = $this->freelancer();

        // Without the shift it covers, the check-in has no hours to be judged against.
        $this->checkInAt($freelancer, '02:03:00')->assertUnprocessable()->assertJsonValidationErrors('cover_shift_id');

        $this->checkInAt($freelancer, '02:03:00', ['cover_shift_id' => $early->id])
            ->assertOk()
            ->assertJsonPath('requires_approval', true);

        $row = Attendance::where('freelancer_id', $freelancer->id)->sole();
        $this->assertSame(AttendanceStatus::PendingApproval, $row->status);
        $this->assertTrue($row->is_off_schedule);
        $this->assertSame(0, $row->check_in_late_minutes);
    }

    public function test_day_off_check_in_follows_the_window_of_the_cover_shift_not_the_company_hours(): void
    {
        $dawn = $this->shift('03:30', '11:30');
        $freelancer = $this->freelancer();
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => self::TUESDAY, 'is_day_off' => true]);

        $this->checkInAt($freelancer, '03:00:00', ['cover_shift_id' => $dawn->id])->assertOk();
    }

    public function test_night_shift_check_in_after_midnight_still_belongs_to_last_evening(): void
    {
        $night = $this->shift('22:00', '06:00');
        $freelancer = $this->freelancer();
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => '2026-11-16', 'shift_id' => $night->id]);

        $this->checkInAt($freelancer, '01:59:00')->assertOk();

        $row = Attendance::where('freelancer_id', $freelancer->id)->sole();
        $this->assertSame('2026-11-16', $row->date->toDateString());
        $this->assertSame(239, $row->late_minutes);
    }

    // ── Pulang cepat ─────────────────────────────────────────────────────────

    public function test_check_out_before_the_shift_end_records_early_leave_minutes(): void
    {
        $freelancer = $this->freelancer($this->shift('09:00', '18:00'));
        $this->checkInAt($freelancer, '08:55:00')->assertOk();

        $this->checkOutAt($freelancer, '16:30:40')
            ->assertOk()
            ->assertJsonPath('attendance.early_leave_minutes', 89);

        $this->assertSame(89, Attendance::where('freelancer_id', $freelancer->id)->sole()->early_leave_minutes);
    }

    public function test_check_out_at_or_after_the_end_is_not_early(): void
    {
        $shift = $this->shift('09:00', '18:00');
        $onTime = $this->freelancer($shift);
        $lastSeconds = $this->freelancer($shift);

        $this->checkInAt($onTime, '09:00:00')->assertOk();
        $this->checkOutAt($onTime, '18:05:00')->assertOk();
        $this->checkInAt($lastSeconds, '09:00:00')->assertOk();
        $this->checkOutAt($lastSeconds, '17:59:30')->assertOk();

        $this->assertSame(0, Attendance::where('freelancer_id', $onTime->id)->sole()->early_leave_minutes);
        $this->assertSame(0, Attendance::where('freelancer_id', $lastSeconds->id)->sole()->early_leave_minutes);
    }

    public function test_night_shift_early_leave_counts_to_the_next_morning(): void
    {
        $night = $this->shift('22:00', '06:00');
        $freelancer = $this->freelancer();
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => '2026-11-16', 'shift_id' => $night->id]);
        $this->checkInAt($freelancer, '01:59:00')->assertOk();

        $this->checkOutAt($freelancer, '04:00:00')->assertOk();

        $this->assertSame(120, Attendance::where('freelancer_id', $freelancer->id)->sole()->early_leave_minutes);
    }

    public function test_split_shift_early_leave_is_judged_per_segment(): void
    {
        $freelancer = $this->freelancer($this->shift('08:00', '12:00', '17:00', '21:00'));
        $this->checkInAt($freelancer, '08:00:00')->assertOk();
        $this->checkOutAt($freelancer, '11:00:00')->assertOk();
        $this->checkInAt($freelancer, '16:30:00')->assertOk();
        $this->checkOutAt($freelancer, '21:00:00')->assertOk();

        $rows = Attendance::where('freelancer_id', $freelancer->id)->orderBy('segment')->get();
        $this->assertSame([60, 0], $rows->pluck('early_leave_minutes')->all());
    }

    public function test_moving_the_check_out_later_clears_the_early_leave(): void
    {
        $freelancer = $this->freelancer($this->shift('09:00', '18:00'));
        $this->checkInAt($freelancer, '09:00:00')->assertOk();
        $this->checkOutAt($freelancer, '12:00:00')->assertOk();
        $row = Attendance::where('freelancer_id', $freelancer->id)->sole();
        $this->assertSame(360, $row->early_leave_minutes);

        $row->update(['check_out' => Carbon::parse(self::TUESDAY.' 18:00:00')]);

        $this->assertSame(0, $row->fresh()->early_leave_minutes);
    }

    // ── Reports ──────────────────────────────────────────────────────────────

    public function test_team_recap_and_its_excel_count_early_leave(): void
    {
        $freelancer = $this->freelancer($this->shift('09:00', '18:00'));
        $this->checkInAt($freelancer, '09:00:00')->assertOk();
        $this->checkOutAt($freelancer, '17:00:00')->assertOk();

        $pic = User::factory()->create(['is_active' => true]);
        $pic->forceFill(['tenant_id' => $this->company->tenant_id, 'data_scope' => 'all'])->save();
        $pic->assignRole(['Admin', 'PIC']);

        $row = collect(app(TeamRecapService::class)->recap($pic, Carbon::parse(self::TUESDAY)))
            ->firstWhere('worker.id', $freelancer->id);
        $this->assertSame(1, $row['early_leave_days']);
        $this->assertSame(60, $row['early_leave_minutes']);

        $sheet = IOFactory::load(app(TeamRecapService::class)->export($pic, Carbon::parse(self::TUESDAY)))->getActiveSheet()->toArray();
        $headings = $sheet[0];
        $data = collect($sheet)->first(fn (array $line): bool => $line[4] === 'Freelance QA');
        $this->assertSame(1, (int) $data[array_search('Pulang Cepat (kali)', $headings, true)]);
        $this->assertSame(60, (int) $data[array_search('Total Pulang Cepat (menit)', $headings, true)]);
    }

    public function test_laporan_absensi_status_shows_early_leave(): void
    {
        $row = new Attendance(['status' => AttendanceStatus::OnTime, 'late_minutes' => 0, 'early_leave_minutes' => 45]);

        $this->assertSame('Hadir · Pulang cepat (45m)', app(LaporanAbsensi::class)->statusLabel($row));
    }
}
