<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Pages\ApprovalSaya;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Services\ApprovalFlowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A named approver (a stage that names one user) may act only while they are active and still belong
 * to the Klien of the requester, on the API as well as on the Approval Saya page.
 */
class ApprovalTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalFlowService $service;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private Company $company;

    private Division $division;

    private User $namedApprover;

    private User $worker;

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

        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(7, 30));

        $this->seed(RoleSeeder::class);

        $this->service = app(ApprovalFlowService::class);
        $this->tenant = Tenant::factory()->create(['name' => 'Klien Uji', 'code' => 'KU']);
        $this->otherTenant = Tenant::factory()->create(['name' => 'Klien Lain', 'code' => 'KL']);

        $this->company = $this->company($this->tenant, 'PT Uji', 'UJI');
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);

        $this->namedApprover = $this->worker('BOD', 'Dewi Direktur', DataScope::All);
        $this->worker = $this->worker('Employee', 'Budi Operasional');

        ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Persetujuan Direktur',
            'approver_type' => 'user',
            'user_id' => $this->namedApprover->id,
        ]);
    }

    public function test_approver_yang_disebut_langsung_bisa_menyetujui_lewat_api(): void
    {
        $leave = $this->createLeave();

        Sanctum::actingAs($this->namedApprover);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])->assertOk();

        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_approver_yang_dipindah_ke_klien_lain_tidak_bisa_memproses_lewat_api(): void
    {
        $leave = $this->createLeave();

        $this->namedApprover->forceFill(['tenant_id' => $this->otherTenant->id])->save();

        $this->assertFalse($this->service->isUserAuthorizedToApprove($leave->fresh(), $this->namedApprover->fresh()));

        Sanctum::actingAs($this->namedApprover->fresh());
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approve');
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'rejected', 'rejection_note' => 'Tidak'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reject');

        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame(['pending'], $leave->approvalSteps()->pluck('status')->all());
    }

    public function test_approver_yang_dipindah_ke_klien_lain_tidak_bisa_memproses_lewat_panel(): void
    {
        $leave = $this->createLeave();

        $this->namedApprover->forceFill(['tenant_id' => $this->otherTenant->id])->save();

        $this->actingAs($this->namedApprover->fresh());
        Livewire::test(ApprovalSaya::class)
            ->callAction('approve', arguments: ['type' => 'leave', 'id' => $leave->id])
            ->assertNotified('Pengajuan tidak bisa disetujui');

        Livewire::test(ApprovalSaya::class)
            ->callAction('reject', arguments: ['type' => 'leave', 'id' => $leave->id], data: ['reason' => 'Tidak'])
            ->assertNotified('Pengajuan tidak bisa ditolak');

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_approver_tidak_bisa_memproses_setelah_badan_usaha_pemohon_dipindah_ke_klien_lain(): void
    {
        $leave = $this->createLeave();

        $this->company->forceFill(['tenant_id' => $this->otherTenant->id])->save();

        $this->assertFalse($this->service->isUserAuthorizedToApprove($leave->fresh(), $this->namedApprover));
        $this->assertSame([], $this->service->getPendingRequestsForUser($this->namedApprover, LeaveRequest::class)->pluck('id')->all());

        Sanctum::actingAs($this->namedApprover);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approve');

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_approver_nonaktif_yang_disebut_langsung_tidak_bisa_menyetujui(): void
    {
        $leave = $this->createLeave();

        $this->namedApprover->forceFill(['is_active' => false])->save();

        $this->assertFalse($this->service->isUserAuthorizedToApprove($leave->fresh(), $this->namedApprover->fresh()));
    }

    public function test_approver_berdasarkan_peran_tetap_bisa_menyetujui(): void
    {
        ApprovalFlow::query()->delete();
        ApprovalFlow::create([
            'company_id' => $this->company->id,
            'request_type' => 'leave',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Persetujuan Approver',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);
        $approver = $this->worker('Approver', 'Andi Approver', DataScope::All);
        $leave = $this->createLeave();

        Sanctum::actingAs($approver);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])->assertOk();

        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_superadmin_tetap_bisa_memproses_pengajuan_klien_mana_pun(): void
    {
        $leave = $this->createLeave();

        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole('Superadmin');

        Sanctum::actingAs($superadmin);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])->assertOk();

        $this->assertSame('approved', $leave->fresh()->status);
    }

    public function test_tahap_dengan_approver_yang_dipindah_ke_klien_lain_diteruskan_ke_admin_klien(): void
    {
        $admin = $this->worker('Admin', 'Ani Admin', DataScope::All);
        $leave = $this->createLeave();

        $this->namedApprover->forceFill(['tenant_id' => $this->otherTenant->id])->save();
        Notification::fake();

        $this->travel(3)->days();
        $this->assertSame(1, $this->service->remindStalled(2));

        Notification::assertSentTo($admin, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->namedApprover, ApprovalRequestedNotification::class);
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_tahap_dengan_approver_yang_dipindah_ke_klien_lain_dilaporkan_tanpa_pemegang(): void
    {
        $this->assertSame([], ApprovalFlowService::unstaffedStages($this->company));

        $this->namedApprover->forceFill(['tenant_id' => $this->otherTenant->id])->save();

        $this->assertSame([[
            'request_type' => 'leave',
            'stage' => 'Persetujuan Direktur',
            'who' => 'user yang dipilih',
        ]], ApprovalFlowService::unstaffedStages($this->company->fresh()));
    }

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        return $company;
    }

    private function worker(string $role, string $name, ?DataScope $scope = null): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $this->company->tenant_id])->save();
        $user->assignRole($role);

        if ($scope) {
            $user->grantDataAccess($scope);
        }

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => $this->company->code.'-'.$user->id,
            'full_name' => $name,
            'join_date' => '2024-01-02',
            'status' => 'active',
        ]);

        return $user;
    }

    private function createLeave(): LeaveRequest
    {
        $leave = LeaveRequest::create([
            'employee_id' => $this->worker->employee->id,
            'leave_type' => 'permission',
            'start_date' => '2026-11-23',
            'end_date' => '2026-11-23',
            'days_count' => 1,
            'reason' => 'Izin keluarga',
            'status' => 'pending',
        ]);

        $this->service->generateSteps($leave, 'leave');

        return $leave->fresh();
    }
}
