<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftSwapRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ShiftSwapService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reviewer probes for Tukar Shift.
 */
class Review_shift_swapTest extends TestCase
{
    use RefreshDatabase;

    private const TUESDAY = '2026-11-17';

    private const THURSDAY = '2026-11-19';

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private Shift $morning;

    private Shift $afternoon;

    private Shift $night;

    private Shift $split;

    private User $approver;

    private User $andi;

    private User $budi;

    private User $citra;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.onesignal.app_id' => 'qa-fake-app',
            'services.onesignal.rest_api_key' => 'qa-fake-key',
        ]);
        Http::fake();
        Notification::fake();

        // Monday morning.
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(7, 30));

        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Tukar', 'code' => 'KT']);
        $this->company = $this->makeCompany('PT Tukar', 'TKR', $this->tenant);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        $this->morning = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '07:00', 'end_time' => '15:00']);
        $this->afternoon = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'start_time' => '13:00', 'end_time' => '21:00']);
        $this->night = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'start_time' => '22:00', 'end_time' => '06:00']);
        $this->split = Shift::create(['company_id' => $this->company->id, 'name' => 'Split', 'start_time' => '08:00', 'end_time' => '12:00', 'second_start_time' => '17:00', 'second_end_time' => '21:00']);

        ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => 'shift_swap',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Persetujuan Tukar Shift',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);

        $this->approver = $this->employeeAccount('Approver', 'Rina Approver', DataScope::All);
        $this->andi = $this->employeeAccount('Employee', 'Andi Kasir');
        $this->budi = $this->employeeAccount('Employee', 'Budi Dapur');
        $this->citra = $this->employeeAccount('Employee', 'Citra Barista');
    }

    /**
     * The colleague (B) is a party to the swap, yet when B holds an approval role over the requester B may
     * give the final approval of a swap that rewrites B's own schedule.
     */
    public function test_counterpart_cannot_approve_the_swap_they_are_party_to(): void
    {
        $dedi = $this->employeeAccount('Approver', 'Dedi Supervisor', DataScope::All);
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($dedi, self::TUESDAY, $this->afternoon);

        $swapId = $this->fileSwap($this->andi, $dedi, self::TUESDAY, self::TUESDAY)->assertCreated()->json('data.id');
        $this->accept($dedi, $swapId);

        Sanctum::actingAs($dedi);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertUnprocessable();

        $this->assertSame(ShiftSwapRequest::STATUS_PENDING, ShiftSwapRequest::findOrFail($swapId)->status);
    }

    /**
     * Freelancers only work when rostered (WorkScheduleService::isOffSchedule): a freelancer with no row and
     * no default shift is off that day, the natural target of a give away, but cellState reads it as
     * "working, schedule not set" and the request is refused.
     */
    public function test_unrostered_freelancer_can_take_a_given_away_shift(): void
    {
        $fajar = $this->freelancerAccount('Fajar Lepas');
        $this->schedule($this->andi, self::TUESDAY, $this->morning);

        $this->fileSwap($this->andi, $fajar, self::TUESDAY, null, ShiftSwapRequest::TYPE_GIVE_AWAY)->assertCreated();
    }

    /**
     * Same-day swap after the requester's shift already ended (absent, no check-in): the swap erases the
     * absence and hands the colleague a shift that is already over.
     */
    public function test_same_day_swap_after_the_requester_shift_ended_is_refused(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(20, 0));
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($this->budi, self::TUESDAY, $this->night);

        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->assertUnprocessable();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function sameDaySchedules(): void
    {
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($this->budi, self::TUESDAY, $this->afternoon);
    }

    private function fileSwap(User $requester, User $colleague, string $date, ?string $colleagueDate, string $type = ShiftSwapRequest::TYPE_SWAP): TestResponse
    {
        $profile = $colleague->employee ?? $colleague->intern ?? $colleague->freelancer;

        Sanctum::actingAs($requester);

        return $this->postJson('/api/v1/shift-swaps', array_filter([
            'type' => $type,
            'requester_date' => $date,
            'counterpart_date' => $colleagueDate,
            'colleague_type' => ShiftSwapService::workerType($profile),
            'colleague_id' => $profile->id,
            'reason' => 'Ada keperluan keluarga',
        ], fn ($value): bool => $value !== null));
    }

    private function accept(User $colleague, int $swapId): void
    {
        Sanctum::actingAs($colleague);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertOk();
    }

    private function approvedSwap(User $requester, User $colleague, string $date, ?string $colleagueDate, string $type = ShiftSwapRequest::TYPE_SWAP): ShiftSwapRequest
    {
        $swapId = $this->fileSwap($requester, $colleague, $date, $colleagueDate, $type)->assertCreated()->json('data.id');
        $this->accept($colleague, $swapId);

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertOk();

        return ShiftSwapRequest::findOrFail($swapId);
    }

    private function schedule(User $worker, string $date, ?Shift $shift, ?string $section = null): ShiftSchedule
    {
        return ShiftSchedule::create([
            'date' => $date,
            $this->workerColumn($worker) => $this->profile($worker)->id,
            'shift_id' => $shift?->id,
            'is_day_off' => $shift === null,
            'section' => $section,
        ]);
    }

    private function scheduleOf(User $worker, string $date): ?ShiftSchedule
    {
        return ShiftSchedule::query()->where($this->workerColumn($worker), $this->profile($worker)->id)->whereDate('date', $date)->first();
    }

    private function assertWorks(User $worker, string $date, Shift $shift): void
    {
        $schedule = $this->scheduleOf($worker, $date);
        $this->assertNotNull($schedule, "{$worker->name} has a schedule on {$date}.");
        $this->assertFalse($schedule->is_day_off);
        $this->assertSame($shift->id, $schedule->shift_id, "{$worker->name} works {$shift->name} on {$date}.");
    }

    private function assertDayOff(User $worker, string $date): void
    {
        $schedule = $this->scheduleOf($worker, $date);
        $this->assertNotNull($schedule);
        $this->assertTrue($schedule->is_day_off, "{$worker->name} is off on {$date}.");
        $this->assertNull($schedule->shift_id);
    }

    private function checkIn(User $worker, string $date): void
    {
        $attendance = new Attendance([
            'date' => $date,
            'check_in' => "{$date} 06:55:00",
            'status' => 'on_time',
        ]);
        $attendance->forceFill([$this->workerColumn($worker) => $this->profile($worker)->id, 'segment' => 1]);
        $attendance->saveQuietly();
    }

    private function profile(User $worker): Employee|Intern|Freelancer
    {
        return $worker->employee()->first() ?? $worker->intern()->first() ?? $worker->freelancer()->first();
    }

    private function workerColumn(User $worker): string
    {
        return match (true) {
            $this->profile($worker) instanceof Employee => 'employee_id',
            $this->profile($worker) instanceof Intern => 'intern_id',
            default => 'freelancer_id',
        };
    }

    private function makeCompany(string $name, string $code, Tenant $tenant): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
    }

    private function employeeAccount(string $role, string $name, ?DataScope $scope = null, ?Company $company = null, ?Division $division = null): User
    {
        $company ??= $this->company;
        $division ??= $this->division;

        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole($role);

        if ($scope) {
            $user->grantDataAccess($scope);
        }

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => $company->code.'-'.$user->id,
            'full_name' => $name,
            'join_date' => '2024-01-02',
            'status' => 'active',
        ]);

        return $user;
    }

    private function internAccount(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole('Intern');

        Intern::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'NIS-'.$user->id,
            'full_name' => $name,
            'institution' => 'Politeknik Uji',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        return $user;
    }

    private function freelancerAccount(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole('Freelancer');

        Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => $name,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ]);

        return $user;
    }
}
