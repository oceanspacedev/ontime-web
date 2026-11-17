<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\OvertimeRequests\Pages\CreateOvertimeRequest;
use App\Filament\Resources\OvertimeRequests\Pages\EditOvertimeRequest;
use App\Filament\Resources\OvertimeRequests\Pages\ListOvertimeRequests;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\OvertimeRequestService;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Overtime rules: server-side duration (midnight, max 12 hours), the -/+ 31 day window, no overlapping
 * requests, hours of processed requests are fixed, and only a manager in scope records the actual duration.
 */
class OvertimeRequestRulesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    private User $freelancerUser;

    private Freelancer $freelancer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onesignal.app_id' => null, 'services.whatsapp_gateway.url' => null]);
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-11-16 20:00:00'));
        $this->seed(RoleSeeder::class);

        $this->tenant = Tenant::factory()->create(['name' => 'Klien Lembur', 'code' => 'KLB']);
        $this->company = $this->makeCompany('PT Lembur', 'LBR');
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet', 'is_active' => true]);

        ApprovalFlow::create([
            'company_id' => $this->company->id, 'request_type' => 'overtime', 'step_number' => 1, 'step_order' => 1,
            'name' => 'Persetujuan lembur', 'approver_type' => 'role', 'approver_role' => 'Admin',
        ]);

        $this->freelancerUser = $this->makeUser('Freelancer');
        $this->freelancer = Freelancer::create([
            'user_id' => $this->freelancerUser->id, 'company_id' => $this->company->id,
            'division_id' => $this->division->id, 'full_name' => 'Fre Lance', 'status' => 'active',
        ]);

        $this->admin = $this->makeUser('Admin');
        $this->admin->grantDataAccess(DataScope::All);
    }

    // ── API: submit and approve ─────────────────────────────────────────────

    public function test_freelancer_submits_through_api_and_manager_approves(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $id = $this->postJson('/api/v1/overtime-requests', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', 180)
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/approvals/overtime/{$id}/process", ['action' => 'approved'])->assertOk();

        $overtime = OvertimeRequest::findOrFail($id);
        $this->assertSame('approved', $overtime->status);
        $this->assertNull($overtime->actual_duration_minutes);
    }

    public function test_api_ignores_a_typed_duration_and_actual_duration(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', [...$this->payload(), 'duration_minutes' => 999, 'actual_duration_minutes' => 999])
            ->assertCreated();

        $overtime = OvertimeRequest::sole();
        $this->assertSame(180, $overtime->duration_minutes);
        $this->assertNull($overtime->actual_duration_minutes);
    }

    // ── Duration ────────────────────────────────────────────────────────────

    public function test_overtime_past_midnight_counts_into_the_next_day(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '22:00', 'end_time' => '02:00']))
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', 240);
    }

    public function test_end_time_equal_to_start_time_is_refused(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '17:00', 'end_time' => '17:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');

        $this->assertSame(0, OvertimeRequest::count());
    }

    public function test_twelve_hours_is_the_maximum(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '20:00', 'end_time' => '08:01']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time' => 'Durasi lembur maksimal 12 jam.']);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '20:00', 'end_time' => '08:00']))
            ->assertCreated()
            ->assertJsonPath('data.duration_minutes', 720);
    }

    public function test_one_minute_before_start_is_a_near_24_hour_range_and_refused(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '17:00', 'end_time' => '16:59']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');
    }

    // ── Date window ─────────────────────────────────────────────────────────

    public function test_date_may_be_today_or_up_to_31_days_back_or_ahead(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        foreach (['2026-11-16', '2026-10-16', '2026-12-17'] as $date) {
            $this->postJson('/api/v1/overtime-requests', $this->payload(['date' => $date]))->assertCreated();
        }

        $this->postJson('/api/v1/overtime-requests', $this->payload(['date' => '2026-10-15']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Tanggal lembur paling lama 31 hari ke belakang.']);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['date' => '2026-12-18']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Tanggal lembur paling jauh 31 hari ke depan.']);

        $this->assertSame(3, OvertimeRequest::count());
    }

    // ── Overlap ─────────────────────────────────────────────────────────────

    public function test_identical_and_overlapping_requests_are_refused_with_a_clear_message(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', $this->payload())->assertCreated();

        $this->postJson('/api/v1/overtime-requests', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time' => 'Jam lembur bentrok dengan pengajuan lembur lain pada 16/11/2026 pukul 17:00–20:00 (menunggu persetujuan).']);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '19:59', 'end_time' => '21:00']))->assertUnprocessable();
        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '16:00', 'end_time' => '23:00']))->assertUnprocessable();

        $this->assertSame(1, OvertimeRequest::count());
    }

    public function test_back_to_back_and_split_day_requests_are_allowed(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        // A split-shift worker may do overtime before the first and after the second segment.
        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '06:00', 'end_time' => '08:00']))->assertCreated();
        $this->postJson('/api/v1/overtime-requests', $this->payload())->assertCreated();
        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '20:00', 'end_time' => '21:00']))->assertCreated();

        $this->assertSame(3, OvertimeRequest::count());
    }

    public function test_overlap_is_detected_across_midnight_in_both_directions(): void
    {
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '22:00', 'end_time' => '02:00']))->assertCreated();

        // The next day 01:00-03:00 overlaps the 00:00-02:00 tail of yesterday's overtime.
        $this->postJson('/api/v1/overtime-requests', $this->payload(['date' => '2026-11-17', 'start_time' => '01:00', 'end_time' => '03:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_time');

        // Starting exactly when yesterday's overtime ends is fine.
        $this->postJson('/api/v1/overtime-requests', $this->payload(['date' => '2026-11-17', 'start_time' => '02:00', 'end_time' => '04:00']))->assertCreated();

        // The day before: 23:00-03:00 runs into the 16th but ends long before its 22:00 overtime.
        $this->postJson('/api/v1/overtime-requests', $this->payload(['date' => '2026-11-15', 'start_time' => '23:00', 'end_time' => '03:00']))->assertCreated();

        // A new overtime on the 16th 01:00-02:30 overlaps the tail of the 15th's overtime.
        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '01:00', 'end_time' => '02:30']))->assertUnprocessable();

        $this->assertSame(3, OvertimeRequest::count());
    }

    public function test_approved_overtime_blocks_but_rejected_overtime_frees_the_hours(): void
    {
        $approved = $this->makeOvertime(['status' => 'approved']);
        $this->makeOvertime(['start_time' => '21:00', 'end_time' => '23:00', 'status' => 'rejected']);

        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '18:00', 'end_time' => '19:00']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time' => 'Jam lembur bentrok dengan pengajuan lembur lain pada 16/11/2026 pukul 17:00–20:00 (sudah disetujui).']);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '21:00', 'end_time' => '23:00']))->assertCreated();

        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_another_workers_overtime_never_blocks(): void
    {
        $otherUser = $this->makeUser('Freelancer');
        Freelancer::create([
            'user_id' => $otherUser->id, 'company_id' => $this->company->id,
            'division_id' => $this->division->id, 'full_name' => 'Rekan', 'status' => 'active',
        ]);

        Sanctum::actingAs($this->freelancerUser);
        $this->postJson('/api/v1/overtime-requests', $this->payload())->assertCreated();

        Sanctum::actingAs($otherUser);
        $this->postJson('/api/v1/overtime-requests', $this->payload())->assertCreated();
    }

    // ── API update ──────────────────────────────────────────────────────────

    public function test_put_with_only_end_time_equal_to_stored_start_is_refused(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/overtime-requests', $this->payload(['end_time' => '19:00']))->json('data.id');

        $this->putJson("/api/v1/overtime-requests/{$id}", ['end_time' => '17:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');

        $this->assertSame(120, OvertimeRequest::findOrFail($id)->duration_minutes);
    }

    public function test_put_recomputes_duration_from_the_stored_start(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/overtime-requests', $this->payload())->json('data.id');

        $this->putJson("/api/v1/overtime-requests/{$id}", ['end_time' => '01:00', 'duration_minutes' => 5])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 480);

        $this->putJson("/api/v1/overtime-requests/{$id}", ['start_time' => '12:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time' => 'Durasi lembur maksimal 12 jam.']);

        $this->assertSame(480, OvertimeRequest::findOrFail($id)->duration_minutes);
    }

    public function test_put_may_not_move_into_another_request_but_may_keep_its_own_hours(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $this->postJson('/api/v1/overtime-requests', $this->payload(['start_time' => '06:00', 'end_time' => '08:00']))->assertCreated();
        $id = $this->postJson('/api/v1/overtime-requests', $this->payload())->json('data.id');

        // Its own hours never count as an overlap.
        $this->putJson("/api/v1/overtime-requests/{$id}", ['reason' => 'Stok opname gudang', 'end_time' => '20:00'])->assertOk();

        $this->putJson("/api/v1/overtime-requests/{$id}", ['start_time' => '07:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_time');

        $this->putJson("/api/v1/overtime-requests/{$id}", ['date' => '2027-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->assertSame('17:00', substr((string) OvertimeRequest::findOrFail($id)->start_time, 0, 5));
    }

    public function test_reason_only_put_on_a_legacy_request_is_not_rechecked(): void
    {
        // Filed before the rules existed: 14 hours, overlapping another pending request, 40 days back.
        $this->makeOvertime(['date' => '2026-10-07', 'start_time' => '09:00', 'end_time' => '10:00', 'duration_minutes' => 60]);
        $legacy = $this->makeOvertime(['date' => '2026-10-07', 'start_time' => '08:00', 'end_time' => '22:00', 'duration_minutes' => 840]);
        Sanctum::actingAs($this->freelancerUser);

        $this->putJson("/api/v1/overtime-requests/{$legacy->id}", ['reason' => 'Rincian tugas'])->assertOk();

        // Sending the unchanged stored date and times along is not a change either.
        $this->putJson("/api/v1/overtime-requests/{$legacy->id}", [
            'date' => '2026-10-07', 'start_time' => '08:00', 'end_time' => '22:00', 'reason' => 'Rincian tugas lagi',
        ])->assertOk();

        $fresh = $legacy->fresh();
        $this->assertSame('Rincian tugas lagi', $fresh->reason);
        $this->assertSame(840, $fresh->duration_minutes);

        // Changing the hours is still checked against every rule.
        $this->putJson("/api/v1/overtime-requests/{$legacy->id}", ['end_time' => '21:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_time' => 'Durasi lembur maksimal 12 jam.']);
    }

    public function test_put_cannot_move_overtime_past_the_contract_end_date(): void
    {
        $this->freelancer->forceFill(['end_date' => '2026-11-20'])->save();
        Sanctum::actingAs($this->freelancerUser);

        $this->postJson('/api/v1/overtime-requests', $this->payload(['date' => '2026-11-25']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Tanggal lembur melewati akhir kontrak Anda (20/11/2026).']);

        $id = $this->postJson('/api/v1/overtime-requests', $this->payload(['date' => '2026-11-18']))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/overtime-requests/{$id}", ['date' => '2026-11-25'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Tanggal lembur melewati akhir kontrak Anda (20/11/2026).']);

        $this->putJson("/api/v1/overtime-requests/{$id}", ['date' => '2026-11-20'])->assertOk();

        $this->assertSame('2026-11-20', OvertimeRequest::findOrFail($id)->date->toDateString());
    }

    public function test_put_is_refused_for_a_worker_whose_status_ended(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/overtime-requests', $this->payload())->assertCreated()->json('data.id');

        $this->freelancer->forceFill(['status' => 'resigned'])->save();

        $this->putJson("/api/v1/overtime-requests/{$id}", ['start_time' => '16:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Status kerja Anda tidak aktif. Hubungi koordinator atau HR.']);

        $this->putJson("/api/v1/overtime-requests/{$id}", ['reason' => 'Ubah alasan'])->assertUnprocessable();

        $this->assertSame('17:00', substr((string) OvertimeRequest::findOrFail($id)->start_time, 0, 5));
    }

    public function test_put_on_approved_overtime_is_refused(): void
    {
        $overtime = $this->makeOvertime(['status' => 'approved']);
        Sanctum::actingAs($this->freelancerUser);

        $this->putJson("/api/v1/overtime-requests/{$overtime->id}", ['end_time' => '23:00'])->assertUnprocessable();

        $this->assertSame(180, $overtime->fresh()->duration_minutes);
    }

    // ── Web create / edit ───────────────────────────────────────────────────

    public function test_web_create_derives_duration_and_refuses_overlap_and_far_dates(): void
    {
        $this->actingAs($this->freelancerUser);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateOvertimeRequest::class)
            ->fillForm(['date' => '2026-11-16', 'start_time' => '22:00', 'end_time' => '01:30', 'reason' => 'Tutup toko'])
            ->fillForm(['duration_minutes' => 30, 'actual_duration_minutes' => 600])
            ->call('create')
            ->assertHasNoFormErrors();

        $overtime = OvertimeRequest::sole();
        $this->assertSame(210, $overtime->duration_minutes);
        $this->assertNull($overtime->actual_duration_minutes);
        $this->assertSame(1, $overtime->approvalSteps()->count());

        Livewire::test(CreateOvertimeRequest::class)
            ->fillForm(['date' => '2026-11-17', 'start_time' => '00:30', 'end_time' => '02:00', 'reason' => 'Lagi'])
            ->call('create')
            ->assertHasFormErrors(['start_time']);

        Livewire::test(CreateOvertimeRequest::class)
            ->fillForm(['date' => '2026-11-16', 'start_time' => '10:00', 'end_time' => '10:00', 'reason' => 'Sama'])
            ->call('create')
            ->assertHasFormErrors(['end_time']);

        Livewire::test(CreateOvertimeRequest::class)
            ->fillForm(['date' => '2026-09-01', 'start_time' => '10:00', 'end_time' => '12:00', 'reason' => 'Lama'])
            ->call('create')
            ->assertHasFormErrors(['date']);

        $this->assertSame(1, OvertimeRequest::count());
    }

    public function test_manager_edit_of_pending_overtime_recomputes_duration(): void
    {
        $overtime = $this->makeOvertime();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditOvertimeRequest::class, ['record' => $overtime->id])
            ->fillForm(['end_time' => '22:00', 'duration_minutes' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(300, $overtime->fresh()->duration_minutes);
        $this->assertSame('pending', $overtime->fresh()->status);
    }

    public function test_manager_edit_refuses_overlap_with_another_request(): void
    {
        $this->makeOvertime(['start_time' => '06:00', 'end_time' => '08:00', 'duration_minutes' => 120]);
        $overtime = $this->makeOvertime();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditOvertimeRequest::class, ['record' => $overtime->id])
            ->fillForm(['start_time' => '07:00', 'end_time' => '09:00'])
            ->call('save')
            ->assertHasFormErrors(['start_time']);

        Livewire::test(EditOvertimeRequest::class, ['record' => $overtime->id])
            ->fillForm(['start_time' => '07:00'])
            ->call('save')
            ->assertHasFormErrors(['end_time']);

        $this->assertSame(180, $overtime->fresh()->duration_minutes);
    }

    public function test_hours_of_approved_overtime_are_locked_on_web_edit(): void
    {
        $overtime = $this->makeOvertime(['status' => 'approved']);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditOvertimeRequest::class, ['record' => $overtime->id])
            ->assertFormFieldDisabled('date')
            ->assertFormFieldDisabled('start_time')
            ->assertFormFieldDisabled('end_time')
            ->fillForm(['end_time' => '23:00', 'reason' => 'Alasan diperjelas'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $overtime->fresh();
        $this->assertSame('20:00', substr((string) $fresh->end_time, 0, 5));
        $this->assertSame(180, $fresh->duration_minutes);
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('Alasan diperjelas', $fresh->reason);
    }

    public function test_manager_edit_cannot_move_overtime_past_the_contract_end_date(): void
    {
        $this->freelancer->forceFill(['end_date' => '2026-11-20'])->save();
        $overtime = $this->makeOvertime(['date' => '2026-11-18']);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditOvertimeRequest::class, ['record' => $overtime->id])
            ->fillForm(['date' => '2026-11-25'])
            ->call('save')
            ->assertHasFormErrors(['date']);

        $this->assertSame('2026-11-18', $overtime->fresh()->date->toDateString());

        Livewire::test(EditOvertimeRequest::class, ['record' => $overtime->id])
            ->fillForm(['date' => '2026-11-20'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-11-20', $overtime->fresh()->date->toDateString());
    }

    public function test_manager_edit_refuses_new_hours_when_an_approver_acted_after_the_page_opened(): void
    {
        $overtime = $this->makeOvertime();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(EditOvertimeRequest::class, ['record' => $overtime->id])
            ->fillForm(['end_time' => '23:00']);

        // An approver approves while the save request runs: after the page loaded its pending copy of the
        // record (the worker is read right after it), before the hours are written.
        $approved = false;
        Freelancer::retrieved(function () use (&$approved, $overtime): void {
            if (! $approved) {
                $approved = true;
                OvertimeRequest::query()->whereKey($overtime->id)->update(['status' => 'approved']);
            }
        });

        $page->call('save')->assertHasFormErrors(['start_time']);

        $fresh = $overtime->fresh();
        $this->assertSame('20:00', substr((string) $fresh->end_time, 0, 5));
        $this->assertSame(180, $fresh->duration_minutes);
        $this->assertSame('approved', $fresh->status);
    }

    public function test_overtime_list_loads_worker_companies_without_a_query_per_row(): void
    {
        foreach (['2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16'] as $date) {
            $this->makeOvertime(['status' => 'approved', 'date' => $date]);
        }

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // A lazy load of one row's company reads it by id alone: the eager load reads all of them with "in".
        $singleCompanyLoads = 0;
        DB::listen(function (QueryExecuted $query) use (&$singleCompanyLoads): void {
            if (preg_match('/from [`"]companies[`"] where [`"]companies[`"]\.[`"]id[`"] = \?/i', $query->sql) === 1) {
                $singleCompanyLoads++;
            }
        });

        Livewire::test(ListOvertimeRequests::class)
            ->assertActionVisible(TestAction::make('fillActualDuration')->table(OvertimeRequest::query()->first()));

        $this->assertSame(0, $singleCompanyLoads);
    }

    // ── Actual duration ─────────────────────────────────────────────────────

    public function test_manager_in_scope_records_actual_duration_on_approved_overtime(): void
    {
        $overtime = $this->makeOvertime(['status' => 'approved']);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListOvertimeRequests::class)
            ->assertActionVisible(TestAction::make('fillActualDuration')->table($overtime))
            ->callAction(TestAction::make('fillActualDuration')->table($overtime), ['actual_duration_minutes' => 150])
            ->assertHasNoFormErrors();

        $this->assertSame(150, $overtime->fresh()->actual_duration_minutes);
    }

    public function test_actual_duration_must_be_between_one_minute_and_twelve_hours(): void
    {
        $overtime = $this->makeOvertime(['status' => 'approved']);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([0, 721, -5] as $minutes) {
            Livewire::test(ListOvertimeRequests::class)
                ->callAction(TestAction::make('fillActualDuration')->table($overtime), ['actual_duration_minutes' => $minutes])
                ->assertHasFormErrors(['actual_duration_minutes']);
        }

        $this->assertNull($overtime->fresh()->actual_duration_minutes);

        $this->expectException(ValidationException::class);
        app(OvertimeRequestService::class)->fillActualDuration($overtime, $this->admin, 721);
    }

    public function test_actual_duration_is_hidden_on_pending_overtime(): void
    {
        $overtime = $this->makeOvertime();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListOvertimeRequests::class)
            ->assertActionHidden(TestAction::make('fillActualDuration')->table($overtime));

        $this->assertFalse(OvertimeRequestService::canFillActualDuration($overtime, $this->admin));
    }

    public function test_requester_never_records_their_own_actual_duration(): void
    {
        $overtime = $this->makeOvertime(['status' => 'approved']);

        $this->assertFalse(OvertimeRequestService::canFillActualDuration($overtime, $this->freelancerUser));

        // Not even when the requester is a manager themself.
        $this->freelancerUser->assignRole('Admin');
        $this->freelancerUser->grantDataAccess(DataScope::All);
        $this->assertFalse(OvertimeRequestService::canFillActualDuration($overtime->fresh(), $this->freelancerUser->fresh()));

        try {
            app(OvertimeRequestService::class)->fillActualDuration($overtime, $this->freelancerUser->fresh(), 600);
            $this->fail('The requester recorded their own actual duration.');
        } catch (ValidationException) {
            $this->assertNull($overtime->fresh()->actual_duration_minutes);
        }
    }

    public function test_manager_outside_data_access_cannot_record_actual_duration(): void
    {
        $overtime = $this->makeOvertime(['status' => 'approved']);
        $otherCompany = $this->makeCompany('PT Lain', 'LAN');

        $outsider = $this->makeUser('Admin');
        $outsider->grantDataAccess(DataScope::Companies, [$otherCompany->id]);

        $approverInScope = $this->makeUser('Approver');
        $approverInScope->grantDataAccess(DataScope::Companies, [$this->company->id]);

        $employeeOnly = $this->makeUser('Employee');

        $this->assertFalse(OvertimeRequestService::canFillActualDuration($overtime, $outsider));
        $this->assertFalse(OvertimeRequestService::canFillActualDuration($overtime, $employeeOnly));
        $this->assertTrue(OvertimeRequestService::canFillActualDuration($overtime, $approverInScope));
    }

    public function test_approval_through_service_keeps_actual_duration_empty_until_a_manager_fills_it(): void
    {
        Sanctum::actingAs($this->freelancerUser);
        $id = $this->postJson('/api/v1/overtime-requests', $this->payload())->json('data.id');
        $overtime = OvertimeRequest::findOrFail($id);

        $this->assertFalse(OvertimeRequestService::canFillActualDuration($overtime, $this->admin));

        app(ApprovalFlowService::class)->approveStep($overtime, $this->admin);
        app(OvertimeRequestService::class)->fillActualDuration($overtime->fresh(), $this->admin, 165);

        $this->assertSame(165, $overtime->fresh()->actual_duration_minutes);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'date' => '2026-11-16',
            'start_time' => '17:00',
            'end_time' => '20:00',
            'reason' => 'Stok opname',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOvertime(array $attributes = []): OvertimeRequest
    {
        return OvertimeRequest::create([
            'freelancer_id' => $this->freelancer->id,
            'date' => '2026-11-16',
            'start_time' => '17:00',
            'end_time' => '20:00',
            'duration_minutes' => 180,
            'reason' => 'Stok opname',
            'status' => 'pending',
            'current_step' => 1,
            ...$attributes,
        ]);
    }

    private function makeCompany(string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $this->tenant->id;
        $company->save();

        return $company;
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $this->tenant->id])->save();
        $user->assignRole($role);

        return $user;
    }
}
