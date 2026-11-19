<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Pages\ApprovalSaya;
use App\Filament\Resources\ApprovalFlows\Pages\EditApprovalFlow;
use App\Filament\Resources\ShiftSwapRequests\Pages\ManageShiftSwapRequests;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use App\Models\ShiftSwapRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalResolvedNotification;
use App\Notifications\ShiftSwapUpdatedNotification;
use App\Services\ApprovalFlowService;
use App\Services\ShiftSwapService;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tukar Shift: a worker asks a colleague of the same company to trade schedules (or take over a shift), the
 * colleague answers, then the company's shift_swap flow decides and the schedules are swapped atomically.
 */
class ShiftSwapRequestTest extends TestCase
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

    // ── Happy paths ───────────────────────────────────────────────────────

    public function test_same_day_swap_goes_from_colleague_to_approver_and_swaps_both_schedules(): void
    {
        $andiSchedule = $this->schedule($this->andi, self::TUESDAY, $this->morning, section: 'Kasir');
        $budiSchedule = $this->schedule($this->budi, self::TUESDAY, $this->afternoon, section: 'Dapur');

        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)
            ->assertCreated()
            ->assertJsonPath('data.status', ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE)
            ->assertJsonPath('data.days.0.requester_after', 'Siang (13:00 - 21:00) · Dapur')
            ->json('data.id');

        Notification::assertSentTo($this->budi, ShiftSwapUpdatedNotification::class, fn ($notification): bool => $notification->event === 'requested');
        Notification::assertNotSentTo($this->approver, ApprovalRequestedNotification::class);

        // Not in any approver's inbox until the colleague accepts.
        Sanctum::actingAs($this->approver);
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonCount(0, 'shift_swaps');

        Sanctum::actingAs($this->budi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', ShiftSwapRequest::STATUS_PENDING);

        Notification::assertSentTo($this->andi, ShiftSwapUpdatedNotification::class, fn ($notification): bool => $notification->event === 'accepted');
        Notification::assertSentTo($this->approver, ApprovalRequestedNotification::class, fn ($notification): bool => $notification->requestType === 'shift_swap');

        Sanctum::actingAs($this->approver);
        $this->getJson('/api/v1/approvals/pending')
            ->assertOk()
            ->assertJsonCount(1, 'shift_swaps')
            ->assertJsonPath('shift_swaps.0.counterpart.full_name', 'Budi Dapur');

        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved', 'current_step' => 1])
            ->assertOk()
            ->assertJsonPath('status', ShiftSwapRequest::STATUS_APPROVED);

        $andiSchedule->refresh();
        $budiSchedule->refresh();
        $this->assertSame($this->afternoon->id, $andiSchedule->shift_id);
        $this->assertSame('Dapur', $andiSchedule->section);
        $this->assertSame('Tukar shift dengan Budi Dapur', $andiSchedule->notes);
        $this->assertSame($this->morning->id, $budiSchedule->shift_id);
        $this->assertSame('Kasir', $budiSchedule->section);
        $this->assertSame('Tukar shift dengan Andi Kasir', $budiSchedule->notes);

        $swap = ShiftSwapRequest::findOrFail($swapId);
        $this->assertNotNull($swap->applied_at);
        $this->assertSame((int) $this->approver->id, (int) $swap->applied_by);
        Notification::assertSentTo($this->andi, ApprovalResolvedNotification::class, fn ($notification): bool => $notification->status === 'approved');
        Notification::assertSentTo($this->budi, ShiftSwapUpdatedNotification::class, fn ($notification): bool => $notification->event === 'approved');
    }

    public function test_cross_date_swap_trades_both_days(): void
    {
        // Andi works Tuesday and is off Thursday; Budi the other way round.
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($this->budi, self::TUESDAY, null);
        $this->schedule($this->budi, self::THURSDAY, $this->night);
        $this->schedule($this->andi, self::THURSDAY, null);

        $swap = $this->approvedSwap($this->andi, $this->budi, self::TUESDAY, self::THURSDAY);

        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, $swap->status);
        $this->assertDayOff($this->andi, self::TUESDAY);
        $this->assertWorks($this->budi, self::TUESDAY, $this->morning);
        $this->assertWorks($this->andi, self::THURSDAY, $this->night);
        $this->assertDayOff($this->budi, self::THURSDAY);
    }

    public function test_give_away_hands_the_shift_to_a_free_colleague_and_gives_the_requester_the_day_off(): void
    {
        $this->schedule($this->andi, self::TUESDAY, $this->split);
        $this->schedule($this->budi, self::TUESDAY, null);

        $swap = $this->approvedSwap($this->andi, $this->budi, self::TUESDAY, null, ShiftSwapRequest::TYPE_GIVE_AWAY);

        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, $swap->status);
        $this->assertNull($swap->counterpart_date);
        $this->assertDayOff($this->andi, self::TUESDAY);
        $this->assertWorks($this->budi, self::TUESDAY, $this->split);
    }

    public function test_give_away_is_refused_when_the_colleague_works_that_day(): void
    {
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($this->budi, self::TUESDAY, $this->afternoon);

        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, null, ShiftSwapRequest::TYPE_GIVE_AWAY)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'tidak libur'));

        $this->assertSame(0, ShiftSwapRequest::count());
    }

    public function test_default_shift_without_a_schedule_row_is_swapped_into_explicit_rows(): void
    {
        $this->andi->employee->update(['shift_id' => $this->morning->id]);
        $this->schedule($this->budi, self::TUESDAY, $this->night);

        $this->approvedSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY);

        $this->assertWorks($this->andi, self::TUESDAY, $this->night);
        $this->assertWorks($this->budi, self::TUESDAY, $this->morning);
    }

    public function test_intern_and_freelancer_can_swap_with_each_other(): void
    {
        $intern = $this->internAccount('Indah Magang');
        $freelancer = $this->freelancerAccount('Fajar Lepas');
        $this->schedule($intern, self::TUESDAY, $this->morning);
        $this->schedule($freelancer, self::TUESDAY, $this->afternoon);

        $swap = $this->approvedSwap($intern, $freelancer, self::TUESDAY, self::TUESDAY);

        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, $swap->status);
        $this->assertWorks($intern, self::TUESDAY, $this->afternoon);
        $this->assertWorks($freelancer, self::TUESDAY, $this->morning);
    }

    public function test_today_is_allowed_while_nobody_checked_in(): void
    {
        $this->schedule($this->andi, '2026-11-16', $this->afternoon);
        $this->schedule($this->budi, '2026-11-16', $this->night);

        $swap = $this->approvedSwap($this->andi, $this->budi, '2026-11-16', '2026-11-16');

        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, $swap->status);
        $this->assertWorks($this->andi, '2026-11-16', $this->night);
    }

    // ── The colleague's answer and cancelling ─────────────────────────────

    public function test_colleague_declines_and_the_request_ends(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');

        Sanctum::actingAs($this->budi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/decline", ['reason' => 'Ada acara keluarga'])
            ->assertOk()
            ->assertJsonPath('data.status', ShiftSwapRequest::STATUS_DECLINED)
            ->assertJsonPath('data.rejection_reason', 'Ada acara keluarga');

        Notification::assertSentTo($this->andi, ShiftSwapUpdatedNotification::class, fn ($notification): bool => $notification->event === 'declined');

        // Declined is final: it can no longer be accepted nor processed by an approver.
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertUnprocessable();
        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertUnprocessable();
        $this->assertWorks($this->andi, self::TUESDAY, $this->morning);
    }

    public function test_only_the_colleague_answers_and_only_the_requester_cancels(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');

        Sanctum::actingAs($this->andi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertUnprocessable();

        // Someone outside the request does not even see it.
        Sanctum::actingAs($this->citra);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertNotFound();
        $this->postJson("/api/v1/shift-swaps/{$swapId}/cancel")->assertNotFound();
        $this->getJson("/api/v1/shift-swaps/{$swapId}")->assertNotFound();

        Sanctum::actingAs($this->budi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/cancel")->assertUnprocessable();

        $this->assertSame(ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE, ShiftSwapRequest::findOrFail($swapId)->status);
    }

    public function test_requester_cancels_while_waiting_for_the_colleague(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');

        Sanctum::actingAs($this->andi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', ShiftSwapRequest::STATUS_CANCELLED);

        Notification::assertSentTo($this->budi, ShiftSwapUpdatedNotification::class, fn ($notification): bool => $notification->event === 'cancelled');

        Sanctum::actingAs($this->budi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertUnprocessable();
    }

    public function test_requester_cancels_while_waiting_for_the_approver_and_it_leaves_the_inbox(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        Sanctum::actingAs($this->andi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/cancel")->assertOk();

        Sanctum::actingAs($this->approver);
        $this->getJson('/api/v1/approvals/pending')->assertJsonCount(0, 'shift_swaps');
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertUnprocessable();
        $this->assertWorks($this->andi, self::TUESDAY, $this->morning);
    }

    public function test_an_approved_swap_can_no_longer_be_cancelled(): void
    {
        $this->sameDaySchedules();
        $swap = $this->approvedSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY);

        Sanctum::actingAs($this->andi);
        $this->postJson("/api/v1/shift-swaps/{$swap->id}/cancel")->assertUnprocessable();
        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, $swap->fresh()->status);
    }

    public function test_rejected_by_the_approver_leaves_the_schedules_alone(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'rejected', 'rejection_note' => 'Kasir harus Andi'])
            ->assertOk()
            ->assertJsonPath('status', ShiftSwapRequest::STATUS_REJECTED);

        $this->assertWorks($this->andi, self::TUESDAY, $this->morning);
        $this->assertWorks($this->budi, self::TUESDAY, $this->afternoon);
        Notification::assertSentTo($this->andi, ApprovalResolvedNotification::class, fn ($notification): bool => $notification->status === 'rejected');
        Notification::assertSentTo($this->budi, ShiftSwapUpdatedNotification::class, fn ($notification): bool => $notification->event === 'rejected');
    }

    // ── Chaos: schedules change, people check in, dates pass ──────────────

    public function test_schedule_changed_after_the_request_fails_the_swap_instead_of_overwriting_it(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        // The coordinator moves Andi to the night shift in the meantime.
        ShiftSchedule::query()->where('employee_id', $this->andi->employee->id)->firstOrFail()->update(['shift_id' => $this->night->id]);

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', ShiftSwapService::STALE_MESSAGE);

        $swap = ShiftSwapRequest::findOrFail($swapId);
        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $swap->status);
        $this->assertSame(ShiftSwapService::STALE_MESSAGE, $swap->failure_reason);
        // The stage is closed by the system with the reason (not left pending), and nobody is told it was rejected.
        $step = $swap->approvalSteps()->sole();
        $this->assertSame('rejected', $step->status);
        $this->assertNull($step->actioned_by);
        $this->assertSame('Gagal diterapkan: '.ShiftSwapService::STALE_MESSAGE, $step->notes);
        $this->assertWorks($this->andi, self::TUESDAY, $this->night);
        $this->assertWorks($this->budi, self::TUESDAY, $this->afternoon);
        Notification::assertNotSentTo($this->andi, ApprovalResolvedNotification::class);
        Notification::assertSentTo($this->andi, ShiftSwapUpdatedNotification::class, fn ($notification): bool => $notification->event === 'failed');
        Notification::assertSentTo($this->budi, ShiftSwapUpdatedNotification::class, fn ($notification): bool => $notification->event === 'failed');

        // A failed swap leaves no pending stage behind, so the named approver is not kept in an inbox.
        Sanctum::actingAs($this->approver);
        $this->getJson('/api/v1/approvals/pending')->assertJsonCount(0, 'shift_swaps');
    }

    public function test_schedule_changed_before_the_colleague_accepts_fails_on_accept(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');

        ShiftSchedule::query()->where('employee_id', $this->budi->employee->id)->firstOrFail()->update(['shift_id' => $this->morning->id]);

        Sanctum::actingAs($this->budi);
        $this->postJson("/api/v1/shift-swaps/{$swapId}/accept")->assertUnprocessable();

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, ShiftSwapRequest::findOrFail($swapId)->status);
    }

    public function test_request_is_refused_once_either_worker_checked_in_that_day(): void
    {
        $this->schedule($this->andi, '2026-11-16', $this->morning);
        $this->schedule($this->budi, '2026-11-16', $this->afternoon);
        $this->checkIn($this->budi, '2026-11-16');

        $this->fileSwap($this->andi, $this->budi, '2026-11-16', '2026-11-16')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sudah presensi'));
    }

    public function test_check_in_after_acceptance_fails_the_final_approval(): void
    {
        // Filed before Budi's 07:00 shift opens for check-in.
        $this->travelTo(now()->setTime(5, 30));
        $this->schedule($this->andi, '2026-11-16', $this->afternoon);
        $this->schedule($this->budi, '2026-11-16', $this->morning);
        $swapId = $this->fileSwap($this->andi, $this->budi, '2026-11-16', '2026-11-16')->json('data.id');
        $this->accept($this->budi, $swapId);

        // Budi turns up for his morning shift before the approver decides.
        $this->checkIn($this->budi, '2026-11-16');

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sudah presensi'));

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, ShiftSwapRequest::findOrFail($swapId)->status);
        $this->assertWorks($this->budi, '2026-11-16', $this->morning);
    }

    public function test_past_dates_are_refused(): void
    {
        $this->schedule($this->andi, '2026-11-15', $this->morning);
        $this->schedule($this->budi, '2026-11-15', $this->afternoon);

        $this->fileSwap($this->andi, $this->budi, '2026-11-15', '2026-11-15')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tukar shift hanya bisa untuk hari ini atau tanggal yang akan datang.');

        // A swap whose second date already passed is refused as well.
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, '2026-11-15')->assertUnprocessable();
    }

    public function test_open_requests_expire_after_their_date_and_cannot_be_approved(): void
    {
        $this->sameDaySchedules();
        $awaitingId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');

        $this->schedule($this->citra, self::THURSDAY, $this->morning);
        $this->schedule($this->budi, self::THURSDAY, $this->afternoon);
        $pendingId = $this->fileSwap($this->citra, $this->budi, self::THURSDAY, self::THURSDAY)->json('data.id');
        $this->accept($this->budi, $pendingId);

        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(8, 0));

        Sanctum::actingAs($this->andi);
        $this->getJson('/api/v1/shift-swaps?box=sent')
            ->assertOk()
            ->assertJsonPath('data.0.status', ShiftSwapRequest::STATUS_EXPIRED)
            ->assertJsonPath('data.0.can_cancel', false);

        // Shown as expired, but reading wrote nothing: shift-swaps:expire saves it.
        $this->assertSame(ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE, ShiftSwapRequest::findOrFail($awaitingId)->status);

        // The Thursday request is still open on Wednesday.
        $this->assertSame(ShiftSwapRequest::STATUS_PENDING, ShiftSwapRequest::findOrFail($pendingId)->status);

        // A stale approver screen opened before the date passed is refused at the final approval.
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(8, 0));
        $seenByApprover = ShiftSwapRequest::findOrFail($pendingId);

        try {
            app(ApprovalFlowService::class)->approveStep($seenByApprover, $this->approver);
            $this->fail('An overdue swap must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertSame(ShiftSwapService::EXPIRED_MESSAGE, collect($exception->errors())->flatten()->first());
        }

        $this->assertSame(ShiftSwapRequest::STATUS_EXPIRED, ShiftSwapRequest::findOrFail($pendingId)->status);

        $this->artisan('shift-swaps:expire')->assertSuccessful();
        $this->assertSame(ShiftSwapRequest::STATUS_EXPIRED, ShiftSwapRequest::findOrFail($awaitingId)->status);
        $this->assertWorks($this->citra, self::THURSDAY, $this->morning);
    }

    public function test_expire_command_expires_overdue_open_requests_only(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $approved = ShiftSwapRequest::factory()->create([
            'company_id' => $this->company->id,
            'employee_id' => $this->citra->employee->id,
            'counterpart_employee_id' => $this->budi->employee->id,
            'requester_date' => '2026-11-17',
            'status' => ShiftSwapRequest::STATUS_APPROVED,
        ]);

        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(0, 5));

        $this->artisan('shift-swaps:expire')->assertSuccessful();

        $this->assertSame(ShiftSwapRequest::STATUS_EXPIRED, ShiftSwapRequest::findOrFail($swapId)->status);
        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, $approved->fresh()->status);
    }

    public function test_two_approvers_on_the_same_request_swap_only_once(): void
    {
        $this->sameDaySchedules();
        $secondApprover = $this->employeeAccount('Approver', 'Sari Approver', DataScope::All);
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        $seenByFirst = ShiftSwapRequest::findOrFail($swapId);
        $seenBySecond = ShiftSwapRequest::findOrFail($swapId);

        app(ApprovalFlowService::class)->approveStep($seenByFirst, $this->approver);

        try {
            app(ApprovalFlowService::class)->approveStep($seenBySecond, $secondApprover);
            $this->fail('The second approval must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(ApprovalFlowService::ALREADY_PROCESSED_MESSAGE, collect($exception->errors())->flatten()->first());
        }

        // Swapped once, not swapped back.
        $this->assertWorks($this->andi, self::TUESDAY, $this->afternoon);
        $this->assertWorks($this->budi, self::TUESDAY, $this->morning);
    }

    public function test_two_requests_over_the_same_schedule_cannot_both_be_applied(): void
    {
        $this->sameDaySchedules();
        $this->schedule($this->citra, self::TUESDAY, $this->night);

        $first = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $first);

        // A second request over Andi's Tuesday that slipped in (for example filed from the panel data).
        $service = app(ShiftSwapService::class);
        $second = ShiftSwapRequest::create([
            'company_id' => $this->company->id,
            'employee_id' => $this->citra->employee->id,
            'counterpart_employee_id' => $this->andi->employee->id,
            'type' => ShiftSwapRequest::TYPE_SWAP,
            'requester_date' => self::TUESDAY,
            'counterpart_date' => self::TUESDAY,
            'schedule_snapshot' => $service->snapshot($this->citra->employee, $this->andi->employee, [self::TUESDAY]),
            'status' => ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE,
        ]);
        $second->update(['counterpart_responded_at' => now()]);
        app(ApprovalFlowService::class)->generateSteps($second, 'shift_swap');

        app(ApprovalFlowService::class)->approveStep(ShiftSwapRequest::findOrFail($first), $this->approver);

        $this->expectException(ValidationException::class);

        try {
            app(ApprovalFlowService::class)->approveStep($second->fresh(), $this->approver);
        } finally {
            $this->assertSame(ShiftSwapRequest::STATUS_FAILED, $second->fresh()->status);
            $this->assertWorks($this->citra, self::TUESDAY, $this->night);
            $this->assertWorks($this->andi, self::TUESDAY, $this->afternoon);
        }
    }

    public function test_a_second_open_request_over_the_same_day_is_refused(): void
    {
        $this->sameDaySchedules();
        $this->schedule($this->citra, self::TUESDAY, $this->night);

        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->assertCreated();

        // Budi is already part of an open swap that Tuesday.
        $this->fileSwap($this->citra, $this->budi, self::TUESDAY, self::TUESDAY)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'masih berjalan'));
    }

    // ── Who may swap with whom ────────────────────────────────────────────

    public function test_colleague_of_another_company_is_refused_without_revealing_them(): void
    {
        $other = $this->makeCompany('PT Lain', 'LAIN', Tenant::factory()->create(['name' => 'Klien Lain', 'code' => 'KL']));
        $outsider = $this->employeeAccount('Employee', 'Orang Luar', company: $other, division: Division::create(['company_id' => $other->id, 'name' => 'Luar', 'is_active' => true]));
        $this->schedule($this->andi, self::TUESDAY, $this->morning);

        $this->fileSwap($this->andi, $outsider, self::TUESDAY, self::TUESDAY)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Rekan kerja tidak ditemukan di badan usaha Anda.');

        $this->assertSame(0, ShiftSwapRequest::count());
    }

    public function test_inactive_or_accountless_colleagues_and_swapping_with_yourself_are_refused(): void
    {
        $this->sameDaySchedules();
        $this->budi->employee->update(['status' => 'resigned']);

        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'tidak aktif'));

        $noAccount = Employee::create([
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'TKR-NOACC',
            'full_name' => 'Tanpa Akun',
            'status' => 'active',
        ]);
        ShiftSchedule::create(['date' => self::TUESDAY, 'employee_id' => $noAccount->id, 'shift_id' => $this->afternoon->id]);

        Sanctum::actingAs($this->andi);
        $this->postJson('/api/v1/shift-swaps', [
            'type' => 'swap', 'requester_date' => self::TUESDAY, 'counterpart_date' => self::TUESDAY,
            'colleague_type' => 'employee', 'colleague_id' => $noAccount->id,
        ])->assertUnprocessable()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'belum punya akun'));

        $this->fileSwap($this->andi, $this->andi, self::TUESDAY, self::TUESDAY)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pilih rekan kerja lain untuk bertukar shift.');
    }

    public function test_requester_without_a_shift_that_day_or_identical_schedules_are_refused(): void
    {
        $this->schedule($this->andi, self::TUESDAY, null);
        $this->schedule($this->budi, self::TUESDAY, $this->afternoon);

        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'tidak punya jadwal shift'));

        $this->schedule($this->citra, self::TUESDAY, $this->afternoon);

        $this->fileSwap($this->citra, $this->budi, self::TUESDAY, self::TUESDAY)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sama'));
    }

    public function test_validation_errors_for_missing_fields(): void
    {
        Sanctum::actingAs($this->andi);

        $this->postJson('/api/v1/shift-swaps', ['type' => 'tukar', 'requester_date' => '17-11-2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'requester_date', 'colleague_type', 'colleague_id']);
    }

    // ── Lists, colleagues, flows and the panel ────────────────────────────

    public function test_lists_split_sent_and_received_and_count_what_waits_for_my_answer(): void
    {
        $this->sameDaySchedules();
        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->assertCreated();

        Sanctum::actingAs($this->budi);
        $this->getJson('/api/v1/shift-swaps?box=received')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('awaiting_my_answer', 1)
            ->assertJsonPath('data.0.can_respond', true)
            ->assertJsonPath('data.0.can_cancel', false);
        $this->getJson('/api/v1/shift-swaps?box=sent')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->andi);
        $this->getJson('/api/v1/shift-swaps')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_mine', true)
            ->assertJsonPath('data.0.can_cancel', true);
    }

    public function test_colleagues_endpoint_lists_only_active_colleagues_of_the_same_company_with_their_day(): void
    {
        $this->schedule($this->budi, self::TUESDAY, null);
        $this->schedule($this->citra, self::TUESDAY, $this->night);
        $this->employeeAccount('Employee', 'Dodi Resign')->employee->update(['status' => 'resigned']);
        $other = $this->makeCompany('PT Lain', 'LAIN', $this->tenant);
        $this->employeeAccount('Employee', 'Orang Luar', company: $other, division: Division::create(['company_id' => $other->id, 'name' => 'Luar', 'is_active' => true]));

        Sanctum::actingAs($this->andi);
        $names = $this->getJson('/api/v1/shift-swaps/colleagues?date='.self::TUESDAY)
            ->assertOk()
            ->json('data');

        $byName = collect($names)->keyBy('full_name');
        $this->assertTrue($byName->has('Budi Dapur'));
        $this->assertTrue($byName['Budi Dapur']['is_off']);
        $this->assertSame('Malam (22:00 - 06:00)', $byName['Citra Barista']['schedule_label']);
        $this->assertFalse($byName->has('Andi Kasir'));
        $this->assertFalse($byName->has('Dodi Resign'));
        $this->assertFalse($byName->has('Orang Luar'));
    }

    public function test_new_companies_get_a_shift_swap_flow_and_a_company_without_one_falls_back_to_the_approver_stage(): void
    {
        $fresh = $this->makeCompany('PT Baru', 'BARU', $this->tenant);
        app(ApprovalFlowService::class)->createDefaultFlows($fresh);
        $this->assertTrue(ApprovalFlow::where('company_id', $fresh->id)->where('request_type', 'shift_swap')->exists());

        // A company flow saved before Tukar Shift existed has no shift_swap stage.
        ApprovalFlow::where('company_id', $this->company->id)->where('request_type', 'shift_swap')->delete();
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        $swap = ShiftSwapRequest::findOrFail($swapId);
        $this->assertSame(ShiftSwapRequest::STATUS_PENDING, $swap->status);
        $this->assertSame('Approver', $swap->approvalSteps()->value('approver_role'));
    }

    public function test_panel_lists_swaps_and_the_approver_approves_from_it(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);
        $swap = ShiftSwapRequest::findOrFail($swapId);

        $this->actingAs($this->approver);

        Livewire::test(ManageShiftSwapRequests::class)
            ->assertCanSeeTableRecords([$swap])
            ->callAction(TestAction::make('approveStep')->table($swap))
            ->assertNotified('Tukar shift disetujui');

        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, $swap->fresh()->status);
        $this->assertWorks($this->andi, self::TUESDAY, $this->afternoon);
    }

    public function test_worker_cannot_open_the_panel_list(): void
    {
        $this->actingAs($this->andi);

        $this->get(ManageShiftSwapRequests::getUrl())->assertForbidden();
    }

    // ── Review fixes: who approves ────────────────────────────────────────

    public function test_counterpart_cannot_approve_the_swap_they_are_party_to(): void
    {
        $dedi = $this->employeeAccount('Approver', 'Dedi Approver', DataScope::All);
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($dedi, self::TUESDAY, $this->afternoon);

        $swapId = $this->fileSwap($this->andi, $dedi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($dedi, $swapId);

        Notification::assertNotSentTo($dedi, ApprovalRequestedNotification::class);

        Sanctum::actingAs($dedi);
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonCount(0, 'shift_swaps');
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertUnprocessable();
        $this->assertWorks($dedi, self::TUESDAY, $this->afternoon);

        // Not even through the Admin override.
        $dedi->assignRole('Admin');
        $this->assertFalse(app(ApprovalFlowService::class)->isUserAuthorizedToApprove(ShiftSwapRequest::findOrFail($swapId), $dedi->fresh()));

        // An independent approver still decides.
        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertOk();
        $this->assertWorks($dedi, self::TUESDAY, $this->morning);
    }

    public function test_requester_cannot_act_on_any_stage_of_their_own_swap(): void
    {
        // Two stages: on other requests a requester may act on an intermediate stage of their own role.
        ApprovalFlow::create([
            'company_id' => $this->company->id, 'request_type' => 'shift_swap', 'step_number' => 2, 'step_order' => 2,
            'name' => 'Persetujuan BOD', 'approver_type' => 'role', 'approver_role' => 'BOD',
        ]);
        $eka = $this->employeeAccount('Approver', 'Eka Approver', DataScope::All);
        $this->schedule($eka, self::TUESDAY, $this->morning);
        $this->schedule($this->budi, self::TUESDAY, $this->afternoon);

        $swapId = $this->fileSwap($eka, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        $this->assertFalse(app(ApprovalFlowService::class)->isUserAuthorizedToApprove(ShiftSwapRequest::findOrFail($swapId), $eka));
        Sanctum::actingAs($eka);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertUnprocessable();
    }

    public function test_cross_division_swap_needs_an_approver_reaching_both_workers(): void
    {
        $outletTwo = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet 2', 'is_active' => true]);
        $budiTwo = $this->employeeAccount('Employee', 'Budi Outlet Dua', division: $outletTwo);
        $outletOneApprover = $this->employeeAccount('Approver', 'Ona Outlet Satu');
        $outletOneApprover->grantDataAccess(DataScope::Divisions, divisionIds: [$this->division->id]);

        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($budiTwo, self::TUESDAY, $this->afternoon);
        $swapId = $this->fileSwap($this->andi, $budiTwo, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($budiTwo, $swapId);

        // Outlet 1's approver does not reach Budi's outlet: not notified, not in the inbox, cannot approve.
        Notification::assertNotSentTo($outletOneApprover, ApprovalRequestedNotification::class);
        Sanctum::actingAs($outletOneApprover);
        $this->getJson('/api/v1/approvals/pending')->assertJsonCount(0, 'shift_swaps');
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertUnprocessable();
        $this->assertWorks($budiTwo, self::TUESDAY, $this->afternoon);

        // An approver reaching both outlets decides.
        Notification::assertSentTo($this->approver, ApprovalRequestedNotification::class);
        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertOk();
        $this->assertWorks($budiTwo, self::TUESDAY, $this->morning);
    }

    // ── Review fixes: freelancers follow the engine's notion of "off" ─────

    public function test_unrostered_freelancer_can_take_a_given_away_shift(): void
    {
        $fajar = $this->freelancerAccount('Fajar Lepas');
        $this->schedule($this->andi, self::TUESDAY, $this->morning);

        $swap = $this->approvedSwap($this->andi, $fajar, self::TUESDAY, null, ShiftSwapRequest::TYPE_GIVE_AWAY);

        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, $swap->status);
        $this->assertDayOff($this->andi, self::TUESDAY);
        $this->assertWorks($fajar, self::TUESDAY, $this->morning);
    }

    public function test_freelancer_cross_date_swap_without_a_row_on_the_other_date(): void
    {
        $fajar = $this->freelancerAccount('Fajar Lepas');
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($this->andi, self::THURSDAY, null);
        $this->schedule($fajar, self::THURSDAY, $this->night);

        $this->approvedSwap($this->andi, $fajar, self::TUESDAY, self::THURSDAY);

        $this->assertDayOff($this->andi, self::TUESDAY);
        $this->assertWorks($fajar, self::TUESDAY, $this->morning);
        $this->assertWorks($this->andi, self::THURSDAY, $this->night);
        $this->assertDayOff($fajar, self::THURSDAY);
    }

    public function test_colleagues_endpoint_shows_an_unrostered_freelancer_as_off(): void
    {
        $this->freelancerAccount('Fajar Lepas');

        Sanctum::actingAs($this->andi);
        $byName = collect($this->getJson('/api/v1/shift-swaps/colleagues?date='.self::TUESDAY)->assertOk()->json('data'))->keyBy('full_name');

        $this->assertTrue($byName['Fajar Lepas']['is_off']);
        $this->assertSame('Libur', $byName['Fajar Lepas']['schedule_label']);
    }

    // ── Review fixes: shifts that already started ─────────────────────────

    public function test_same_day_swap_after_the_requester_shift_ended_is_refused(): void
    {
        // Monday 20:00: Andi missed his 07:00-15:00 shift and tries to hand it to Budi.
        $this->travelTo(now()->setTime(20, 0));
        $this->schedule($this->andi, '2026-11-16', $this->morning);
        $this->schedule($this->budi, '2026-11-16', $this->night);

        $this->fileSwap($this->andi, $this->budi, '2026-11-16', '2026-11-16')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sudah dimulai'));

        $this->assertSame(0, ShiftSwapRequest::count());
    }

    public function test_same_day_swap_is_refused_once_the_check_in_window_opened(): void
    {
        $this->schedule($this->andi, '2026-11-16', $this->afternoon);
        $this->schedule($this->budi, '2026-11-16', $this->night);

        // 12:01, the 13:00 shift already accepts check-in.
        $this->travelTo(now()->setTime(12, 1));

        $this->fileSwap($this->andi, $this->budi, '2026-11-16', '2026-11-16')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sudah dimulai'));
    }

    public function test_overnight_shift_counts_from_its_own_date_and_a_started_shift_fails_the_final_approval(): void
    {
        $this->schedule($this->andi, self::TUESDAY, $this->afternoon);
        $this->schedule($this->budi, self::TUESDAY, $this->night);

        // Tuesday 11:30: both shifts are still ahead.
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(11, 30));
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->assertCreated()->json('data.id');
        $this->accept($this->budi, $swapId);

        // The approver only looks at 21:30; Budi's 22:00 night shift (Tuesday's business date) already opened.
        $this->travelTo(now()->setTime(21, 30));

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sudah dimulai'));

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, ShiftSwapRequest::findOrFail($swapId)->status);
        $this->assertWorks($this->andi, self::TUESDAY, $this->afternoon);
        $this->assertWorks($this->budi, self::TUESDAY, $this->night);
    }

    // ── Review fixes: leave on an affected date ───────────────────────────

    public function test_swap_is_refused_when_either_worker_has_approved_or_pending_leave_that_day(): void
    {
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->schedule($this->budi, self::TUESDAY, null);
        $this->schedule($this->budi, self::THURSDAY, $this->night);
        $this->schedule($this->andi, self::THURSDAY, null);
        $leave = $this->leave($this->budi, self::THURSDAY, 'approved');

        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::THURSDAY)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'cuti/izin'));

        $leave->update(['status' => 'pending']);
        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::THURSDAY)->assertUnprocessable();

        // A rejected leave does not count.
        $leave->update(['status' => 'rejected']);
        $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::THURSDAY)->assertCreated();
    }

    public function test_leave_filed_after_acceptance_fails_the_final_approval(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        $this->leave($this->andi, self::TUESDAY, 'pending');

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'cuti/izin'));

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, ShiftSwapRequest::findOrFail($swapId)->status);
        $this->assertWorks($this->andi, self::TUESDAY, $this->morning);
    }

    // ── Review fixes: robustness and performance ──────────────────────────

    public function test_a_schedule_row_inserted_concurrently_fails_the_swap_as_stale_and_writes_nothing(): void
    {
        // Andi has a row, Budi works his default shift (no row): the swap updates Andi's row, then inserts Budi's.
        $this->schedule($this->andi, self::TUESDAY, $this->morning);
        $this->budi->employee->update(['shift_id' => $this->night->id]);
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        // A coordinator creates Budi's Tuesday row at the same moment.
        $budiId = $this->budi->employee->id;
        ShiftSchedule::creating(function (ShiftSchedule $schedule) use ($budiId): void {
            if ((int) $schedule->employee_id === $budiId && ! ShiftSchedule::query()->where('employee_id', $budiId)->exists()) {
                (new ShiftSchedule)->forceFill(['employee_id' => $budiId, 'date' => self::TUESDAY, 'is_day_off' => true])->saveQuietly();
            }
        });

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', ShiftSwapService::STALE_MESSAGE);

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, ShiftSwapRequest::findOrFail($swapId)->status);
        // Andi's row update was undone with the failed insert.
        $this->assertWorks($this->andi, self::TUESDAY, $this->morning);
        $this->assertSame('', (string) $this->scheduleOf($this->andi, self::TUESDAY)->notes);
    }

    public function test_applied_swap_logs_both_roster_writes_with_the_source_swap(): void
    {
        $this->sameDaySchedules();

        $this->approvedSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY);

        $changes = ShiftScheduleChange::query()->where('action', ShiftScheduleChange::ACTION_UPDATED)->orderBy('id')->get();
        $this->assertSame(['swap', 'swap'], $changes->pluck('source')->all());
        $this->assertSame([$this->morning->id, $this->afternoon->id], $changes->map(fn (ShiftScheduleChange $change): int => $change->before['shift_id'])->all());
        $this->assertSame([$this->afternoon->id, $this->morning->id], $changes->map(fn (ShiftScheduleChange $change): int => $change->after['shift_id'])->all());
        $this->assertSame([$this->approver->id, $this->approver->id], $changes->pluck('changed_by')->all());
    }

    public function test_a_schedule_locked_by_a_check_in_while_the_swap_is_written_fails_it_as_stale(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        // Budi checks in on his Tuesday schedule at the moment the swap is being written.
        $budiSchedule = $this->scheduleOf($this->budi, self::TUESDAY);
        ShiftSchedule::saving(function (ShiftSchedule $schedule) use ($budiSchedule): void {
            if ($schedule->is($budiSchedule) && ! Attendance::query()->where('shift_schedule_id', $budiSchedule->id)->exists()) {
                $attendance = new Attendance(['date' => self::TUESDAY, 'check_in' => self::TUESDAY.' 06:55:00', 'status' => 'on_time']);
                $attendance->forceFill(['employee_id' => $budiSchedule->employee_id, 'segment' => 1, 'shift_schedule_id' => $budiSchedule->id])->saveQuietly();
            }
        });

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', ShiftSwapService::STALE_MESSAGE);

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, ShiftSwapRequest::findOrFail($swapId)->status);
        $this->assertWorks($this->andi, self::TUESDAY, $this->morning);
        $this->assertWorks($this->budi, self::TUESDAY, $this->afternoon);
        $this->assertSame(0, ShiftScheduleChange::query()->where('source', 'swap')->count());
    }

    public function test_list_reads_snapshot_shifts_once_for_the_whole_page(): void
    {
        foreach (range(1, 5) as $index) {
            ShiftSwapRequest::factory()->create([
                'company_id' => $this->company->id,
                'employee_id' => $this->andi->employee->id,
                'counterpart_employee_id' => $this->budi->employee->id,
                'status' => ShiftSwapRequest::STATUS_CANCELLED,
                'schedule_snapshot' => [
                    'requester|2026-11-18' => ['off' => false, 'shift_id' => $this->morning->id, 'company_location_id' => null, 'section' => null, 'schedule_id' => null],
                    'counterpart|2026-11-18' => ['off' => false, 'shift_id' => $this->night->id, 'company_location_id' => null, 'section' => null, 'schedule_id' => null],
                ],
                'requester_date' => '2026-11-18',
                'counterpart_date' => '2026-11-18',
            ]);
        }

        $shiftQueries = 0;
        DB::listen(function ($query) use (&$shiftQueries): void {
            if (preg_match('/from\s+["`]?shifts["`]?/i', $query->sql)) {
                $shiftQueries++;
            }
        });

        Sanctum::actingAs($this->andi);
        $this->getJson('/api/v1/shift-swaps?box=sent')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.4.days.0.requester_after', 'Malam (22:00 - 06:00)');

        $this->assertSame(1, $shiftQueries);
    }

    public function test_overdue_swaps_leave_the_inbox_without_a_write_and_the_command_is_scheduled(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));

        Sanctum::actingAs($this->approver);
        $this->getJson('/api/v1/approvals/pending')->assertOk()->assertJsonCount(0, 'shift_swaps');
        $this->assertSame(ShiftSwapRequest::STATUS_PENDING, ShiftSwapRequest::findOrFail($swapId)->status);

        $this->assertTrue(
            collect(app(Schedule::class)->events())->contains(fn ($event): bool => str_contains((string) $event->command, 'shift-swaps:expire')),
            'shift-swaps:expire is scheduled.',
        );
    }

    // ── Review fixes: panel ───────────────────────────────────────────────

    public function test_saving_the_company_flow_keeps_a_shift_swap_stage(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        ApprovalFlow::where('company_id', $this->company->id)->where('request_type', 'shift_swap')->delete();
        app(ApprovalFlowService::class)->createDefaultFlows($this->company);
        ApprovalFlow::where('company_id', $this->company->id)->where('request_type', 'shift_swap')->delete();

        $this->actingAs($superadmin);

        // Without a stored stage the form offers the default one, so saving does not fail nor drop it.
        Livewire::test(EditApprovalFlow::class, ['record' => $this->company->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('approval_flows', ['company_id' => $this->company->id, 'request_type' => 'shift_swap', 'approver_role' => 'Approver']);
    }

    public function test_approval_inbox_lists_and_approves_shift_swaps(): void
    {
        $this->sameDaySchedules();
        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->json('data.id');
        $this->accept($this->budi, $swapId);

        $this->actingAs($this->approver);

        Livewire::test(ApprovalSaya::class)
            ->assertSee('Tukar Shift (1)')
            ->assertSee('Andi Kasir ⇄ Budi Dapur')
            ->callAction(TestAction::make('approve')->arguments(['type' => 'shift_swap', 'id' => $swapId]))
            ->assertNotified('Pengajuan Berhasil Disetujui');

        $this->assertSame(ShiftSwapRequest::STATUS_APPROVED, ShiftSwapRequest::findOrFail($swapId)->status);
        $this->assertWorks($this->andi, self::TUESDAY, $this->afternoon);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function test_approval_succeeds_when_the_stored_snapshot_comes_back_reordered_with_string_ids(): void
    {
        $andiSchedule = $this->schedule($this->andi, self::TUESDAY, $this->morning, section: 'Kasir');
        $budiSchedule = $this->schedule($this->budi, self::TUESDAY, $this->afternoon, section: 'Dapur');

        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->assertCreated()->json('data.id');
        $this->accept($this->budi, $swapId);

        // The same cells as MySQL hands a JSON column back: keys sorted by length then bytes, and (as some
        // drivers do) ids as strings and the flag as 0/1.
        $stored = ShiftSwapRequest::findOrFail($swapId)->schedule_snapshot;
        $reordered = [];

        foreach (array_reverse($stored, preserve_keys: true) as $key => $cell) {
            $reordered[$key] = [
                'off' => (int) $cell['off'],
                'section' => $cell['section'],
                'shift_id' => $cell['shift_id'] === null ? null : (string) $cell['shift_id'],
                'schedule_id' => $cell['schedule_id'] === null ? null : (string) $cell['schedule_id'],
                'company_location_id' => $cell['company_location_id'] === null ? null : (string) $cell['company_location_id'],
            ];
        }

        $this->assertNotSame($stored, $reordered);
        DB::table('shift_swap_requests')->where('id', $swapId)->update(['schedule_snapshot' => json_encode($reordered)]);

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertOk();

        $swap = ShiftSwapRequest::findOrFail($swapId);
        $this->assertSame('approved', $swap->status);
        $this->assertNotNull($swap->applied_at);
        $this->assertSame([(int) $this->afternoon->id, 'Dapur'], [(int) $andiSchedule->fresh()->shift_id, $andiSchedule->fresh()->section]);
        $this->assertSame([(int) $this->morning->id, 'Kasir'], [(int) $budiSchedule->fresh()->shift_id, $budiSchedule->fresh()->section]);
    }

    public function test_approval_still_fails_as_stale_when_a_stored_cell_really_differs(): void
    {
        $this->schedule($this->andi, self::TUESDAY, $this->morning, section: 'Kasir');
        $budiSchedule = $this->schedule($this->budi, self::TUESDAY, $this->afternoon, section: 'Dapur');

        $swapId = $this->fileSwap($this->andi, $this->budi, self::TUESDAY, self::TUESDAY)->assertCreated()->json('data.id');
        $this->accept($this->budi, $swapId);

        $budiSchedule->update(['section' => 'Bar']);

        Sanctum::actingAs($this->approver);
        $this->putJson("/api/v1/approvals/shift_swap/{$swapId}/process", ['action' => 'approved'])->assertUnprocessable();

        $this->assertSame(ShiftSwapRequest::STATUS_FAILED, ShiftSwapRequest::findOrFail($swapId)->status);
        $this->assertSame('Kasir', $this->scheduleOf($this->andi, self::TUESDAY)?->section, 'Nothing was swapped.');
        $this->assertSame('Bar', $budiSchedule->fresh()->section);
    }

    private function leave(User $worker, string $date, string $status): LeaveRequest
    {
        $leave = new LeaveRequest([
            'leave_type' => 'permission',
            'start_date' => $date,
            'end_date' => $date,
            'days_count' => 1,
            'reason' => 'Urusan keluarga',
            'status' => $status,
        ]);
        $leave->forceFill([$this->workerColumn($worker) => $this->profile($worker)->id]);
        $leave->saveQuietly();

        return $leave;
    }

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
