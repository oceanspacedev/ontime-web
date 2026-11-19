<?php

namespace Tests\Feature\Daily;

use App\Enums\DataScope;
use App\Filament\Pages\AbsenHariIni;
use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Resources\TenantResource\Pages\ListTenants;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\PresensiWidget;
use App\Filament\Widgets\SuperadminOverviewWidget;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequestStep;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\AttendanceReminderNotification;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use App\Services\TenantOnboardingService;
use App\Support\ObservabilityAccess;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A normal day seen from the platform Superadmin: they only onboard a Klien with its PIC and then
 * monitor (dashboard, Klien overview, Horizon, Log Viewer). Every daily operation runs inside the
 * Klien without them: no approval stage, no notification, no required login.
 */
class SuperadminMonitorDailyJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private Tenant $klienA;

    private Tenant $klienB;

    private Company $companyA;

    private Company $companyB;

    private Division $divisionA;

    protected function setUp(): void
    {
        parent::setUp();

        // Never reach WhatsApp, OneSignal, Expo, CompreFace or any other outside host.
        config([
            'services.whatsapp_gateway.url' => null,
            'services.whatsapp_gateway.token' => null,
            'services.onesignal.app_id' => null,
            'services.onesignal.rest_api_key' => null,
            'services.face.driver' => 'basic',
        ]);
        Http::preventStrayRequests();
        Http::fake();

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->superadmin = User::factory()->create(['is_active' => true, 'name' => 'Superadmin Platform']);
        $this->superadmin->assignRole('Superadmin');
        $this->superadmin->grantDataAccess(DataScope::All);

        $this->klienA = Tenant::factory()->create(['name' => 'Klien Alfa', 'code' => 'ALFA']);
        $this->klienB = Tenant::factory()->create(['name' => 'Klien Beta', 'code' => 'BETA']);
        $this->companyA = $this->company($this->klienA, 'PT Alfa', 'ALF');
        $this->companyB = $this->company($this->klienB, 'PT Beta', 'BET');
        $this->divisionA = Division::create(['company_id' => $this->companyA->id, 'name' => 'Operasional', 'is_active' => true]);

        // Before the 08:00 start so a check-in is on time.
        $this->travelTo(today()->setTime(7, 30));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();

        app(ApprovalFlowService::class)->createDefaultFlows($company);

        return $company;
    }

    /**
     * @param  list<string>  $roles
     */
    private function account(Tenant $tenant, array $roles, DataScope $scope = DataScope::Own): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess($scope);

        return $user->fresh();
    }

    private function pic(Tenant $tenant): User
    {
        return app(TenantOnboardingService::class)->createPic($tenant, 'PIC '.$tenant->name, fake()->unique()->safeEmail())['user']->fresh();
    }

    private function employee(Company $company, ?Division $division = null): Employee
    {
        $division ??= Division::firstOrCreate(['company_id' => $company->id, 'name' => 'Kantor'], ['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'EMP-'.$user->id,
            'full_name' => 'Karyawan '.$user->id,
            'join_date' => now()->subYear(),
            'status' => 'permanent',
        ]);
    }

    private function freelancer(Company $company, array $attributes = []): Freelancer
    {
        $division = Division::firstOrCreate(['company_id' => $company->id, 'name' => 'Outlet'], ['is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole('Freelancer');

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'full_name' => 'Crew '.$user->id,
            'status' => 'active',
            ...$attributes,
        ]);
    }

    /**
     * Push notifications only go out when OneSignal is configured; fake the channel so nothing leaves the test.
     */
    private function fakePushNotifications(): void
    {
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        Notification::fake();
    }

    private function stepRolesOf(object $request): array
    {
        return ApprovalRequestStep::query()
            ->where('approvable_type', $request->getMorphClass())
            ->where('approvable_id', $request->getKey())
            ->pluck('approver_role')
            ->all();
    }

    // ── Onboarding: the only operational thing the Superadmin does ───────────

    public function test_pagi_superadmin_membuat_klien_dan_pic_lalu_pic_mengambil_alih(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Grup Franchise Gamma',
                'code' => 'GAMMA',
                'is_active' => true,
                'pic_name' => 'Bu Rina',
                'pic_email' => 'rina@gamma.test',
                'pic_phone' => '081234567890',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $tenant = Tenant::query()->where('code', 'GAMMA')->firstOrFail();
        $pic = User::query()->where('email', 'rina@gamma.test')->firstOrFail();

        $this->assertSame($tenant->id, $pic->tenant_id);
        $this->assertTrue($pic->hasRole('Admin') && $pic->hasRole('PIC'));
        $this->assertSame(DataScope::All, $pic->dataScope());
        $this->assertSame(0, Company::query()->where('tenant_id', $tenant->id)->count(), 'The Superadmin hands over an empty Klien: the PIC adds its companies.');

        // Only the Superadmin can hand out the PIC role.
        $this->assertArrayHasKey('PIC', UserResource::assignableRoleOptions());

        // From here the PIC runs the Klien from the app, the Superadmin is not involved.
        Sanctum::actingAs($pic->fresh());
        $this->postJson('/api/v1/client/companies', ['name' => 'PT Gamma Satu', 'code' => 'GM1'])->assertCreated();
        $company = Company::query()->where('code', 'GM1')->firstOrFail();
        $this->assertSame($tenant->id, $company->tenant_id);
        $this->assertTrue(ApprovalFlow::query()->where('company_id', $company->id)->exists());
        $this->assertNotContains('Superadmin', ApprovalFlow::query()->where('company_id', $company->id)->pluck('approver_role')->all());

        // No WhatsApp traffic: the gateway is not configured.
        Http::assertNothingSent();
    }

    /**
     * QA bug: the "Tambah PIC" record action on the Klien table validates the email with
     * ->unique('users', 'email'), which in Filament v5 ignores the current record by default. The
     * record is the Tenant, so the rule becomes "users where tenants.id <> ?" and the query fails
     * (SQLSTATE: no such column / Unknown column 'tenants.id'). Fix: ignoreRecord: false.
     */
    public function test_superadmin_menambah_pic_kedua_dari_tabel_klien(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('addPic')->table($this->klienA), [
                'pic_name' => 'Pak Dodi',
                'pic_email' => 'dodi@alfa.test',
                'pic_phone' => null,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $pic = User::query()->where('email', 'dodi@alfa.test')->firstOrFail();
        $this->assertSame($this->klienA->id, $pic->tenant_id);
        $this->assertTrue($pic->hasRole('PIC') && $pic->hasRole('Admin'));

        // The email still has to be unique across all accounts.
        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('addPic')->table($this->klienB), [
                'pic_name' => 'Pak Dodi Lagi',
                'pic_email' => 'dodi@alfa.test',
            ])
            ->assertHasFormErrors(['pic_email' => 'unique']);
        Http::assertNothingSent();
    }

    // ── Monitoring ───────────────────────────────────────────────────────────

    public function test_superadmin_memantau_dashboard_lintas_klien(): void
    {
        $onTime = $this->employee($this->companyA);
        $late = $this->employee($this->companyB);
        Attendance::create(['employee_id' => $onTime->id, 'date' => today()->toDateString(), 'check_in' => now(), 'status' => 'on_time']);
        Attendance::create(['employee_id' => $late->id, 'date' => today()->toDateString(), 'check_in' => now(), 'status' => 'late', 'late_minutes' => 20]);
        LeaveRequest::create(['employee_id' => $onTime->id, 'leave_type' => 'sick', 'start_date' => today()->addDay()->toDateString(), 'end_date' => today()->addDay()->toDateString(), 'days_count' => 1, 'reason' => 'Sakit', 'status' => 'pending']);

        $this->actingAs($this->superadmin);

        $this->get('/admin')->assertOk();
        $this->assertTrue(SuperadminOverviewWidget::canView());
        $this->assertFalse(PresensiWidget::canView(), 'The Superadmin has no attendance of their own to record.');
        $this->assertFalse(AbsenHariIni::canAccess());

        Livewire::test(SuperadminOverviewWidget::class)
            ->assertOk()
            ->assertSee('2 Badan Usaha')
            // Present counts every worker who came in, the late one included.
            ->assertSee('2 Karyawan')
            ->assertSee('Telat: 1')
            ->assertSee('1 Pengajuan');
    }

    public function test_superadmin_melihat_ringkasan_menu_klien(): void
    {
        $this->pic($this->klienA);
        $this->employee($this->companyA);

        $this->actingAs($this->superadmin);
        $this->assertTrue(TenantResource::canViewAny());
        $this->get(TenantResource::getUrl('index'))->assertOk();

        Livewire::test(ListTenants::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->klienA, $this->klienB])
            ->assertTableColumnStateSet('companies_count', 1, $this->klienA)
            ->assertTableColumnStateSet('users_count', 2, $this->klienA);
    }

    public function test_superadmin_membuka_horizon_dan_log_viewer_sementara_pic_tidak(): void
    {
        $this->assertTrue(ObservabilityAccess::allowed($this->superadmin));
        $this->actingAs($this->superadmin)->get('/horizon')->assertOk();
        $this->actingAs($this->superadmin)->get('/log-viewer')->assertOk();

        $pic = $this->pic($this->klienA);
        $this->assertFalse(ObservabilityAccess::allowed($pic));
        $this->actingAs($pic)->get('/horizon')->assertForbidden();
        $this->actingAs($pic)->get('/log-viewer')->assertForbidden();
    }

    // ── Negative permissions ────────────────────────────────────────────────

    public function test_klien_admin_tidak_melihat_menu_dan_widget_superadmin(): void
    {
        $pic = $this->pic($this->klienA);
        $this->actingAs($pic);

        $this->assertFalse(TenantResource::canViewAny());
        $this->assertFalse(SuperadminOverviewWidget::canView());
        $this->get(TenantResource::getUrl('index'))->assertForbidden();
        $this->assertArrayNotHasKey('PIC', UserResource::assignableRoleOptions());
    }

    public function test_superadmin_tidak_bisa_membuat_badan_usaha_atau_akun_lewat_api_klien(): void
    {
        Sanctum::actingAs($this->superadmin);

        $this->getJson('/api/v1/client/overview')->assertOk()->assertJsonPath('permissions.can_create_company', false);

        $this->postJson('/api/v1/client/companies', ['name' => 'PT Liar', 'code' => 'LIAR'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Superadmin mengelola klien lewat panel web.');
        $this->postJson('/api/v1/client/accounts', ['name' => 'Akun Liar', 'email' => 'liar@example.com', 'roles' => ['Approver'], 'data_scope' => 'own'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Superadmin mengelola klien lewat panel web.');

        $this->assertDatabaseMissing('companies', ['code' => 'LIAR']);
        $this->assertDatabaseMissing('users', ['email' => 'liar@example.com']);

        // Without a worker profile there is nothing to check in: a clean 422, never a crash.
        $this->getJson('/api/v1/attendance/today')->assertStatus(422);
        $this->postJson('/api/v1/attendance/check-in')->assertStatus(422);
        $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => today()->addDay()->toDateString(), 'end_date' => today()->addDay()->toDateString(), 'reason' => 'Uji'])
            ->assertStatus(422);
        $this->assertSame(0, LeaveRequest::query()->count());
    }

    public function test_superadmin_tidak_pernah_ditawarkan_sebagai_tahap_persetujuan(): void
    {
        $this->actingAs($this->pic($this->klienA));

        $this->assertNotContains('Superadmin', ApprovalFlowService::APPROVER_ROLES);
        $this->assertArrayNotHasKey('Superadmin', ApprovalFlowResource::approverRoleOptions());
        $this->assertArrayNotHasKey($this->superadmin->id, ApprovalFlowService::approverUserOptions($this->companyA->id));
        $this->assertFalse(ApprovalFlowService::hasHolderForRole('Superadmin', $this->companyA->id));

        // A stage pointing at the Superadmin account is treated as nobody holding it.
        ApprovalFlow::query()->where('company_id', $this->companyA->id)->where('request_type', 'overtime')->delete();
        ApprovalFlow::create(['company_id' => $this->companyA->id, 'request_type' => 'overtime', 'step_number' => 1, 'step_order' => 1, 'name' => 'Owner', 'approver_type' => 'user', 'user_id' => $this->superadmin->id]);
        $this->assertContains('overtime', array_column(ApprovalFlowService::unstaffedStages($this->companyA), 'request_type'));
    }

    // ── A full working day inside a Klien, no Superadmin involved ────────────

    public function test_hari_kerja_penuh_berjalan_tanpa_superadmin_dan_tanpa_notifikasi_ke_superadmin(): void
    {
        $this->fakePushNotifications();
        $approver = $this->account($this->klienA, ['Approver'], DataScope::All);
        $foreignApprover = $this->account($this->klienB, ['Approver'], DataScope::All);
        $employee = $this->employee($this->companyA, $this->divisionA);
        $employeeUser = User::findOrFail($employee->user_id);

        // 07:30 check-in from the app.
        Sanctum::actingAs($employeeUser);
        $this->postJson('/api/v1/attendance/check-in')->assertOk()->assertJsonPath('message', 'Check in berhasil');

        // Requests during the day.
        $leaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => today()->addDays(2)->toDateString(), 'end_date' => today()->addDays(2)->toDateString(), 'reason' => 'Kontrol dokter'])
            ->assertCreated()->json('data.id');
        $overtimeId = $this->postJson('/api/v1/overtime-requests', ['date' => today()->toDateString(), 'start_time' => '17:00', 'end_time' => '19:00', 'reason' => 'Stok opname'])
            ->assertCreated()->json('data.id');
        $correctionId = $this->postJson('/api/v1/attendance-corrections', ['date' => today()->subDay()->toDateString(), 'corrected_check_in' => '08:00', 'corrected_check_out' => '17:00', 'reason' => 'Lupa absen'])
            ->assertCreated()->json('data.id');
        $resignationId = $this->postJson('/api/v1/resignations', ['resignation_date' => today()->toDateString(), 'last_working_day' => today()->addMonth()->toDateString(), 'reason' => 'Pindah kota'])
            ->assertCreated()->json('data.id');

        foreach ([
            LeaveRequest::findOrFail($leaveId),
            OvertimeRequest::findOrFail($overtimeId),
            AttendanceCorrection::findOrFail($correctionId),
            Resignation::findOrFail($resignationId),
        ] as $request) {
            $roles = $this->stepRolesOf($request);
            $this->assertNotEmpty($roles);
            $this->assertNotContains('Superadmin', $roles, class_basename($request).' must never wait on the Superadmin.');
        }

        // The Klien's own Approver handles everything from the app.
        Sanctum::actingAs($approver);
        $pending = $this->getJson('/api/v1/approvals/pending')->assertOk();
        $this->assertCount(1, $pending->json('leave_requests'));
        $this->assertCount(1, $pending->json('overtime_requests'));
        $this->assertCount(1, $pending->json('attendance_corrections'));
        $this->assertCount(1, $pending->json('resignations'));

        $this->putJson("/api/v1/approvals/leave/{$leaveId}/process", ['action' => 'approved'])->assertOk();
        $this->putJson("/api/v1/approvals/overtime/{$overtimeId}/process", ['action' => 'approved'])->assertOk();
        $this->putJson("/api/v1/approvals/correction/{$correctionId}/process", ['action' => 'rejected', 'rejection_note' => 'Tidak ada bukti'])->assertOk();
        $this->putJson("/api/v1/approvals/resignation/{$resignationId}/process", ['action' => 'approved'])->assertOk();

        $this->assertSame('approved', LeaveRequest::findOrFail($leaveId)->status);
        $this->assertSame('approved', OvertimeRequest::findOrFail($overtimeId)->status);
        $this->assertSame('rejected', AttendanceCorrection::findOrFail($correctionId)->status);
        $this->assertSame('approved', Resignation::findOrFail($resignationId)->status);

        // 17:05 check-out.
        $this->travelTo(today()->setTime(17, 5));
        Sanctum::actingAs($employeeUser->fresh());
        $this->postJson('/api/v1/attendance/check-out')->assertOk();
        $this->assertNotNull(Attendance::query()->where('employee_id', $employee->id)->value('check_out'));

        Notification::assertSentTo($approver, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($foreignApprover, ApprovalRequestedNotification::class);
        Notification::assertNothingSentTo($this->superadmin);
        Http::assertNothingSent();
    }

    public function test_tahap_tanpa_pemegang_jatuh_ke_admin_klien_bukan_superadmin(): void
    {
        $this->fakePushNotifications();
        $pic = $this->pic($this->klienA);
        $employee = $this->employee($this->companyA, $this->divisionA);

        // No Approver in Klien Alfa: the request still reaches someone of the Klien, never the Superadmin.
        Sanctum::actingAs(User::findOrFail($employee->user_id));
        $leaveId = $this->postJson('/api/v1/leave-requests', ['leave_type' => 'sick', 'start_date' => today()->addDays(3)->toDateString(), 'end_date' => today()->addDays(3)->toDateString(), 'reason' => 'Sakit'])
            ->assertCreated()->json('data.id');

        Notification::assertSentTo($pic, ApprovalRequestedNotification::class);
        Notification::assertNothingSentTo($this->superadmin);

        // Three days later the daily reminder nudges the PIC, still not the Superadmin.
        $this->travel(3)->days();
        $this->artisan('approvals:remind-stalled')->assertSuccessful();
        Notification::assertSentToTimes($pic, ApprovalRequestedNotification::class, 2);
        Notification::assertNothingSentTo($this->superadmin);

        // The PIC closes it from the app.
        Sanctum::actingAs($pic);
        $this->putJson("/api/v1/approvals/leave/{$leaveId}/process", ['action' => 'approved'])->assertOk();
        $this->assertSame('approved', LeaveRequest::findOrFail($leaveId)->status);
    }

    public function test_superadmin_hanya_memantau_pengajuan_tanpa_menjadi_penyetuju_wajib(): void
    {
        $this->fakePushNotifications();
        $approver = $this->account($this->klienA, ['Approver'], DataScope::All);
        $employee = $this->employee($this->companyA, $this->divisionA);
        $leave = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type' => 'sick', 'start_date' => today()->addDay()->toDateString(), 'end_date' => today()->addDay()->toDateString(), 'days_count' => 1, 'reason' => 'Sakit', 'status' => 'pending']);
        app(ApprovalFlowService::class)->generateSteps($leave, 'leave');

        // The Superadmin can see the pending request (support/monitoring), but is never notified.
        Sanctum::actingAs($this->superadmin);
        $this->assertCount(1, $this->getJson('/api/v1/approvals/pending')->assertOk()->json('leave_requests'));
        Notification::assertNothingSentTo($this->superadmin);

        // The Klien's approver finishes it alone in one stage.
        Sanctum::actingAs($approver);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", ['action' => 'approved'])->assertOk();
        $this->assertSame('approved', $leave->fresh()->status);
        $this->assertSame(0, ApprovalRequestStep::query()->where('approvable_id', $leave->id)->where('status', 'pending')->count());
    }

    // ── Scheduled commands on a day nobody of the platform logs in ───────────

    public function test_perintah_terjadwal_harian_berjalan_tanpa_superadmin(): void
    {
        $this->fakePushNotifications();
        // A platform with no Superadmin login at all today; the account even being absent must not matter.
        $this->superadmin->delete();
        $this->assertSame(0, User::role('Superadmin')->count());

        $approver = $this->account($this->klienA, ['Approver'], DataScope::All);

        // Expired freelancer contract.
        $expired = $this->freelancer($this->companyA, ['end_date' => today()->subDay()->toDateString()]);
        // Approved resignation whose last day has passed.
        $leaver = $this->employee($this->companyA, $this->divisionA);
        Resignation::create(['employee_id' => $leaver->id, 'resignation_date' => today()->subMonth()->toDateString(), 'last_working_day' => today()->subDay()->toDateString(), 'reason' => 'Pindah', 'status' => 'approved']);
        $waiting = $this->employee($this->companyA, $this->divisionA);
        // A request saved without steps (legacy import).
        LeaveRequest::create(['employee_id' => $waiting->id, 'leave_type' => 'sick', 'start_date' => today()->addDays(9)->toDateString(), 'end_date' => today()->addDays(9)->toDateString(), 'days_count' => 1, 'reason' => 'Lama', 'status' => 'pending']);
        // A scheduled shift for reminders.
        $crew = $this->freelancer($this->companyA);
        $shift = Shift::create(['company_id' => $this->companyA->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'late_tolerance_minutes' => 10]);
        ShiftSchedule::create(['freelancer_id' => $crew->id, 'date' => today()->toDateString(), 'shift_id' => $shift->id]);

        $this->travelTo(today()->setTime(8, 11));

        $this->artisan('workers:close-expired-contracts')->assertSuccessful();
        $this->artisan('employees:apply-resignations')->assertSuccessful();
        $this->artisan('approvals:generate-missing-steps')->assertSuccessful();
        $this->artisan('attendance:send-reminders')->assertSuccessful();
        $this->artisan('sanctum:prune-idle')->assertSuccessful();

        $this->assertSame('completed', $expired->fresh()->status);
        $this->assertFalse((bool) User::findOrFail($expired->user_id)->is_active);
        $this->assertSame('inactive', $leaver->fresh()->status);
        $this->assertFalse((bool) User::findOrFail($leaver->user_id)->is_active);
        Notification::assertSentTo(User::findOrFail($crew->user_id), AttendanceReminderNotification::class);
        $this->assertSame(0, LeaveRequest::query()->whereDoesntHave('approvalSteps')->count(), 'Every request has its stages after the backfill.');
        $this->assertNotContains('Superadmin', ApprovalRequestStep::query()->pluck('approver_role')->filter()->all());

        Http::assertNothingSent();
    }

    /**
     * QA bug: approvals:remind-stalled (scheduled daily 08:00) loads the pending requests with only
     * approvalSteps, then calls getWorkerProfile() on each one. With two or more pending requests
     * of one type Eloquent's lazy-loading guard (on outside production, e.g. staging) throws
     * LazyLoadingViolationException, so the reminder never goes out. In production it is an N+1.
     * Fix: eager load employee/intern/freelancer (+ company/division/user) in remindStalled().
     */
    public function test_pengingat_persetujuan_tertunda_berjalan_dengan_banyak_pengajuan_tanpa_superadmin(): void
    {
        $this->fakePushNotifications();
        $approver = $this->account($this->klienA, ['Approver'], DataScope::All);

        $this->travelTo(today()->subDays(3)->setTime(9, 0));
        foreach ([$this->employee($this->companyA, $this->divisionA), $this->employee($this->companyA, $this->divisionA)] as $worker) {
            $leave = LeaveRequest::create(['employee_id' => $worker->id, 'leave_type' => 'sick', 'start_date' => today()->addDays(5)->toDateString(), 'end_date' => today()->addDays(5)->toDateString(), 'days_count' => 1, 'reason' => 'Sakit', 'status' => 'pending']);
            app(ApprovalFlowService::class)->generateSteps($leave, 'leave');
        }
        $this->travelTo(today()->addDays(3)->setTime(8, 0));
        Notification::fake();

        $this->artisan('approvals:remind-stalled')
            ->expectsOutputToContain('2 pengajuan diingatkan.')
            ->assertSuccessful();

        Notification::assertSentToTimes($approver, ApprovalRequestedNotification::class, 2);
        Notification::assertNothingSentTo($this->superadmin);
        Http::assertNothingSent();
    }

    public function test_jadwal_harian_terdaftar_dan_klien_lain_tidak_tersentuh(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('workers:close-expired-contracts')
            ->expectsOutputToContain('employees:apply-resignations')
            ->expectsOutputToContain('approvals:remind-stalled')
            ->expectsOutputToContain('attendance:send-reminders')
            ->assertSuccessful();

        // Closing contracts in one Klien never reaches the other.
        $expiredA = $this->freelancer($this->companyA, ['end_date' => today()->subDay()->toDateString()]);
        $activeB = $this->freelancer($this->companyB, ['end_date' => today()->addMonth()->toDateString()]);
        $this->artisan('workers:close-expired-contracts')->assertSuccessful();

        $this->assertSame('completed', $expiredA->fresh()->status);
        $this->assertSame('active', $activeB->fresh()->status);
        DataAccessService::flush();
    }
}
