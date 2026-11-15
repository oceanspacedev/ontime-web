<?php

namespace Tests\Feature\Daily;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Filament\Pages\ApprovalSaya;
use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\ShiftSchedules\ShiftScheduleResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalResolvedNotification;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A working day of the Approver and BOD of a Klien: the approval queue on the mobile app and the
 * Persetujuan Masuk inbox on the web, multi-stage flows (Approver, then BOD), who is notified, and
 * everything these roles must not be able to do. The Superadmin only monitors and is never asked.
 */
class ApproverBodDailyJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const OFFICE_LATITUDE = -6.1753924;

    private const OFFICE_LONGITUDE = 106.8271528;

    private User $superadmin;

    private Tenant $klienA;

    private Company $companyA;

    private Division $operasional;

    private Division $gudang;

    private User $pic;

    private User $approver;

    private User $bod;

    private User $budi;

    private User $sari;

    private Tenant $klienB;

    private Company $companyB;

    private User $approverB;

    private User $adminB;

    private User $employeeB;

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

        // Monday morning, before the office opens.
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(7, 30));

        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->create(['name' => 'Superadmin Platform', 'is_active' => true]);
        $this->superadmin->assignRole('Superadmin');

        $this->klienA = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA']);
        $this->companyA = $this->company($this->klienA, 'PT Alfa', 'ALF');
        $this->operasional = Division::create(['company_id' => $this->companyA->id, 'name' => 'Operasional', 'is_active' => true]);
        $this->gudang = Division::create(['company_id' => $this->companyA->id, 'name' => 'Gudang', 'is_active' => true]);

        $this->pic = $this->account($this->klienA, ['Admin', 'PIC'], 'PIC Alfa', DataScope::All);
        $this->approver = $this->worker($this->companyA, $this->operasional, 'Approver', 'Andi Approver');
        $this->approver->grantDataAccess(DataScope::Divisions, divisionIds: [$this->operasional->id]);
        $this->bod = $this->worker($this->companyA, $this->operasional, 'BOD', 'Dewi Direktur');
        $this->bod->grantDataAccess(DataScope::All);
        $this->budi = $this->worker($this->companyA, $this->operasional, 'Employee', 'Budi Operasional');
        $this->sari = $this->worker($this->companyA, $this->gudang, 'Employee', 'Sari Gudang');

        $this->klienB = Tenant::factory()->create(['name' => 'Klien B', 'code' => 'KB']);
        $this->companyB = $this->company($this->klienB, 'PT Beta', 'BET');
        $divisionB = Division::create(['company_id' => $this->companyB->id, 'name' => 'Operasional', 'is_active' => true]);
        $this->approverB = $this->account($this->klienB, ['Approver'], 'Bambang Approver B', DataScope::All);
        $this->adminB = $this->account($this->klienB, ['Admin', 'PIC'], 'PIC Beta', DataScope::All);
        $this->employeeB = $this->worker($this->companyB, $divisionB, 'Employee', 'Eko Beta');

        foreach ([$this->companyA, $this->companyB] as $company) {
            foreach (['leave', 'overtime', 'correction', 'resignation'] as $requestType) {
                $this->flow($company, $requestType, ['Approver', 'BOD']);
            }
            $this->flow($company, 'geofence', ['Approver']);
        }
    }

    public function test_pagi_hari_approver_membuka_antrean_berisi_kelima_jenis_pengajuan_divisinya(): void
    {
        $leave = $this->submitLeave($this->budi);
        $overtime = $this->submitOvertime($this->budi);
        $correction = $this->submitCorrection($this->budi);
        $resignation = $this->submitResignation($this->budi);
        $geofence = $this->checkInOutsideGeofence($this->budi);
        $sariLeave = $this->submitLeave($this->sari);
        $klienBLeave = $this->submitLeave($this->employeeB);

        $queue = $this->pending($this->approver);

        $this->assertSame([$leave->id], array_column($queue['leave_requests'], 'id'), 'Gudang and Klien B are outside the Approver access.');
        $this->assertSame([$overtime->id], array_column($queue['overtime_requests'], 'id'));
        $this->assertSame([$correction->id], array_column($queue['attendance_corrections'], 'id'));
        $this->assertSame([$resignation->id], array_column($queue['resignations'], 'id'));
        $this->assertSame([$geofence->id], array_column($queue['geofence_attendances'], 'id'));

        $bodQueue = $this->pending($this->bod);
        foreach ($bodQueue as $type => $items) {
            $this->assertSame([], $items, "Every request is still at the Approver stage, so the BOD queue ({$type}) is empty.");
        }

        $klienBQueue = $this->pending($this->approverB);
        $this->assertSame([$klienBLeave->id], array_column($klienBQueue['leave_requests'], 'id'));
        $this->assertNotContains($sariLeave->id, array_column($klienBQueue['leave_requests'], 'id'));
    }

    public function test_cuti_dua_tahap_approver_lalu_bod_dan_notifikasi_tepat_sasaran(): void
    {
        $leave = $this->submitLeave($this->budi, start: '2026-11-19', end: '2026-11-20');

        Notification::assertSentTo($this->approver, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->bod, ApprovalRequestedNotification::class, 'BOD hears about it only at the BOD stage.');
        Notification::assertNotSentTo($this->pic, ApprovalRequestedNotification::class, 'Admins only hear about stages set to them or stages without holders.');
        Notification::assertNotSentTo($this->approverB, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->adminB, ApprovalRequestedNotification::class);

        $this->process($this->approver, 'leave', $leave->id, 'approved', 'Sudah dicek dengan jadwal tim')
            ->assertOk()
            ->assertJsonPath('message', 'Pengajuan berhasil disetujui')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('current_step', 2);

        $this->assertSame(0, Attendance::where('employee_id', $this->budi->employee->id)->count(), 'Attendance only changes after the last stage.');
        $this->assertNotContains($leave->id, array_column($this->pending($this->approver)['leave_requests'], 'id'));
        $this->assertSame([$leave->id], array_column($this->pending($this->bod)['leave_requests'], 'id'));
        Notification::assertSentTo($this->bod, ApprovalRequestedNotification::class);

        $this->process($this->bod, 'leave', $leave->id, 'approved')
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame(
            ['approved', 'approved'],
            $leave->approvalSteps()->orderBy('step_order')->pluck('status')->all(),
        );
        $this->assertSame(
            [$this->approver->id, $this->bod->id],
            $leave->approvalSteps()->orderBy('step_order')->pluck('actioned_by')->map(fn ($id): int => (int) $id)->all(),
        );
        $this->assertSame(2, Attendance::where('employee_id', $this->budi->employee->id)->where('status', AttendanceStatus::Leave->value)->count());

        Notification::assertSentTo($this->budi, ApprovalResolvedNotification::class);
        Notification::assertNothingSentTo($this->superadmin);
        Notification::assertNothingSentTo($this->approverB);
        Notification::assertNothingSentTo($this->adminB);
    }

    public function test_lembur_ditolak_approver_dengan_alasan_langsung_menghentikan_alur(): void
    {
        $overtime = $this->submitOvertime($this->budi);

        $this->process($this->approver, 'overtime', $overtime->id, 'rejected')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rejection_note');

        $this->process($this->approver, 'overtime', $overtime->id, 'rejected', 'Lembur tidak ada di rencana kerja')
            ->assertOk()
            ->assertJsonPath('message', 'Pengajuan telah ditolak')
            ->assertJsonPath('status', 'rejected');

        $overtime->refresh();
        $this->assertSame('rejected', $overtime->status);
        $this->assertSame('Lembur tidak ada di rencana kerja', $overtime->rejection_reason);
        $this->assertSame('pending', $overtime->approvalSteps()->where('step_order', 2)->value('status'), 'The BOD stage is never reached.');

        $this->assertSame([], $this->pending($this->bod)['overtime_requests']);
        $this->process($this->bod, 'overtime', $overtime->id, 'approved')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pengajuan ini sudah tidak berstatus pending.');

        Notification::assertSentTo($this->budi, ApprovalResolvedNotification::class);
        Notification::assertNotSentTo($this->bod, ApprovalRequestedNotification::class);
        Notification::assertNothingSentTo($this->superadmin);
    }

    public function test_koreksi_absensi_disetujui_dua_tahap_menerapkan_jam_ke_presensi(): void
    {
        $correction = $this->submitCorrection($this->budi, date: '2026-11-13');

        $this->process($this->approver, 'correction', $correction->id, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->assertFalse(
            Attendance::where('employee_id', $this->budi->employee->id)->whereDate('date', '2026-11-13')->exists(),
            'The correction only applies after the BOD stage.',
        );

        $this->process($this->bod, 'correction', $correction->id, 'approved')->assertOk()->assertJsonPath('status', 'approved');

        $attendance = Attendance::where('employee_id', $this->budi->employee->id)->whereDate('date', '2026-11-13')->firstOrFail();
        $this->assertTrue((bool) $attendance->is_corrected);
        $this->assertSame('08:00', $attendance->check_in->format('H:i'));
        $this->assertSame('17:00', $attendance->check_out->format('H:i'));
        Notification::assertSentTo($this->budi, ApprovalResolvedNotification::class);
    }

    public function test_pengunduran_diri_disetujui_approver_dan_bod(): void
    {
        $resignation = $this->submitResignation($this->budi);

        $this->process($this->approver, 'resignation', $resignation->id, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->process($this->bod, 'resignation', $resignation->id, 'approved')->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame('approved', $resignation->fresh()->status);
        $this->assertTrue((bool) $this->budi->fresh()->is_active, 'The employee keeps working until the last working day.');
        Notification::assertSentTo($this->budi, ApprovalResolvedNotification::class);
        Notification::assertNothingSentTo($this->superadmin);
    }

    public function test_presensi_luar_geofence_diproses_approver_setujui_dan_tolak(): void
    {
        $budiAttendance = $this->checkInOutsideGeofence($this->budi);
        $rina = $this->worker($this->companyA, $this->operasional, 'Employee', 'Rina Operasional');
        $rinaAttendance = $this->checkInOutsideGeofence($rina);

        $this->assertSame(
            [$budiAttendance->id, $rinaAttendance->id],
            array_column($this->pending($this->approver)['geofence_attendances'], 'id'),
        );

        $this->process($this->bod, 'geofence', $budiAttendance->id, 'approved')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approve');

        $this->process($this->approver, 'geofence', $budiAttendance->id, 'approved')->assertOk();
        $this->assertSame(AttendanceStatus::OnTime, $budiAttendance->fresh()->status);

        $this->process($this->approver, 'attendance', $rinaAttendance->id, 'rejected', 'Lokasi tidak sesuai penugasan')->assertOk();
        $this->assertSame(AttendanceStatus::Rejected, $rinaAttendance->fresh()->status);
        $this->assertSame('Lokasi tidak sesuai penugasan', $rinaAttendance->fresh()->rejection_reason);

        Notification::assertSentTo($this->budi, ApprovalResolvedNotification::class);
        Notification::assertSentTo($rina, ApprovalResolvedNotification::class);
        Notification::assertNothingSentTo($this->superadmin);
    }

    public function test_bod_tidak_bisa_memproses_tahap_approver_dan_approver_tidak_bisa_tahap_bod(): void
    {
        $leave = $this->submitLeave($this->budi);

        $this->process($this->bod, 'leave', $leave->id, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
        $this->process($this->bod, 'leave', $leave->id, 'rejected', 'Tidak setuju')->assertUnprocessable()->assertJsonValidationErrors('reject');
        $this->assertSame(1, (int) $leave->fresh()->current_step);
        $this->assertSame('pending', $leave->fresh()->status);

        $this->process($this->approver, 'leave', $leave->id, 'approved')->assertOk();

        $this->process($this->approver, 'leave', $leave->id, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
        $this->process($this->approver, 'leave', $leave->id, 'rejected', 'Saya batalkan')->assertUnprocessable()->assertJsonValidationErrors('reject');
        $this->assertSame(2, (int) $leave->fresh()->current_step);
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_pengajuan_sendiri_tidak_pernah_disetujui_final_oleh_diri_sendiri(): void
    {
        // The BOD files a leave: the Approver passes stage 1, the BOD stage is the final one.
        $bodLeave = $this->submitLeave($this->bod);
        $this->process($this->approver, 'leave', $bodLeave->id, 'approved')->assertOk()->assertJsonPath('current_step', 2);

        $this->assertNotContains($bodLeave->id, array_column($this->pending($this->bod)['leave_requests'], 'id'));
        $this->process($this->bod, 'leave', $bodLeave->id, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
        $this->assertSame('pending', $bodLeave->fresh()->status);

        // The PIC (Admin of the client) settles it instead.
        $this->process($this->pic, 'leave', $bodLeave->id, 'approved')->assertOk()->assertJsonPath('status', 'approved');

        // The Approver files an overtime: passing their own intermediate Approver stage is allowed,
        // the final BOD stage stays with someone else.
        $approverOvertime = $this->submitOvertime($this->approver);
        $this->process($this->approver, 'overtime', $approverOvertime->id, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        $this->process($this->approver, 'overtime', $approverOvertime->id, 'approved')->assertUnprocessable();
        $this->process($this->bod, 'overtime', $approverOvertime->id, 'approved')->assertOk()->assertJsonPath('status', 'approved');

        // A single-stage flow (geofence): the Approver's own attendance is never theirs to approve.
        $approverAttendance = $this->checkInOutsideGeofence($this->approver);
        $this->assertNotContains($approverAttendance->id, array_column($this->pending($this->approver)['geofence_attendances'], 'id'));
        $this->process($this->approver, 'geofence', $approverAttendance->id, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
        $this->assertSame(AttendanceStatus::PendingApproval, $approverAttendance->fresh()->status);
    }

    public function test_tahap_akhir_pengajuan_bod_sendiri_diberitahukan_ke_admin_karena_tidak_ada_pemegang_lain(): void
    {
        $bodLeave = $this->submitLeave($this->bod);
        $this->process($this->approver, 'leave', $bodLeave->id, 'approved')->assertOk();

        // The only BOD is the requester, who cannot give the final approval: nobody but an Admin
        // can act, so the Admins of the client must hear about it instead of the request stalling silently.
        Notification::assertNotSentTo($this->bod, ApprovalRequestedNotification::class);
        Notification::assertSentTo($this->pic, ApprovalRequestedNotification::class);
        Notification::assertNothingSentTo($this->superadmin);
    }

    public function test_tahap_tanpa_pemegang_dialihkan_ke_admin_klien_bukan_superadmin(): void
    {
        // The Approver only reaches Operasional, so the Approver stage of a Gudang worker has no holder.
        $leave = $this->submitLeave($this->sari);

        Notification::assertSentTo($this->pic, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->approver, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->bod, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->adminB, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->approverB, ApprovalRequestedNotification::class);
        Notification::assertNothingSentTo($this->superadmin);

        $this->assertSame([], $this->pending($this->approver)['leave_requests']);
        $this->assertSame([$leave->id], array_column($this->pending($this->pic)['leave_requests'], 'id'));

        $this->process($this->pic, 'leave', $leave->id, 'approved')->assertOk()->assertJsonPath('current_step', 2);
        Notification::assertSentTo($this->bod, ApprovalRequestedNotification::class);

        $this->process($this->bod, 'leave', $leave->id, 'approved')->assertOk()->assertJsonPath('status', 'approved');
        Notification::assertSentTo($this->sari, ApprovalResolvedNotification::class);
    }

    public function test_approver_klien_b_tidak_melihat_dan_tidak_bisa_memproses_pengajuan_klien_a(): void
    {
        $leave = $this->submitLeave($this->budi);
        $klienBLeave = $this->submitLeave($this->employeeB);

        $this->assertNotContains($leave->id, array_column($this->pending($this->approverB)['leave_requests'], 'id'));
        $this->process($this->approverB, 'leave', $leave->id, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
        $this->process($this->approverB, 'leave', $leave->id, 'rejected', 'Coba tolak')->assertUnprocessable()->assertJsonValidationErrors('reject');

        Sanctum::actingAs($this->approverB);
        $this->getJson("/api/v1/leave-requests/{$leave->id}")->assertForbidden();

        // And the other way round: Klien A approvers cannot touch Klien B.
        $this->assertNotContains($klienBLeave->id, array_column($this->pending($this->approver)['leave_requests'], 'id'));
        $this->process($this->approver, 'leave', $klienBLeave->id, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
        Sanctum::actingAs($this->bod);
        $this->getJson("/api/v1/leave-requests/{$klienBLeave->id}")->assertForbidden();

        $this->assertSame('pending', $leave->fresh()->status);
        $this->assertSame(1, (int) $leave->fresh()->current_step);
        $this->assertSame('pending', $klienBLeave->fresh()->status);
        $this->assertSame(0, $leave->approvalSteps()->whereNotNull('actioned_by')->count());
    }

    public function test_approver_dengan_akses_pribadi_tidak_menerima_antrean_orang_lain(): void
    {
        $personalApprover = $this->account($this->klienA, ['Approver'], 'Approver Pribadi', DataScope::Own);
        $leave = $this->submitLeave($this->budi);

        Notification::assertNotSentTo($personalApprover, ApprovalRequestedNotification::class);
        foreach ($this->pending($personalApprover) as $items) {
            $this->assertSame([], $items);
        }
        $this->process($personalApprover, 'leave', $leave->id, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
    }

    public function test_beberapa_approver_di_tahap_sama_cukup_satu_yang_menyetujui(): void
    {
        $secondApprover = $this->account($this->klienA, ['Approver'], 'Citra Approver', DataScope::Companies, companyIds: [$this->companyA->id]);
        $leave = $this->submitLeave($this->budi);

        Notification::assertSentTo($this->approver, ApprovalRequestedNotification::class);
        Notification::assertSentTo($secondApprover, ApprovalRequestedNotification::class);

        $this->process($secondApprover, 'leave', $leave->id, 'approved')->assertOk()->assertJsonPath('current_step', 2);

        $this->assertSame([], $this->pending($this->approver)['leave_requests']);
        $this->process($this->approver, 'leave', $leave->id, 'approved')->assertUnprocessable()->assertJsonValidationErrors('approve');
        $this->assertSame(1, $leave->approvalSteps()->where('status', 'approved')->count());
    }

    public function test_persetujuan_masuk_di_panel_web_approver_setujui_lalu_bod_tolak(): void
    {
        $leave = $this->submitLeave($this->budi);

        $this->actingAs($this->approver, 'web');
        $this->get(ApprovalSaya::getUrl())->assertOk();
        Livewire::test(ApprovalSaya::class)
            ->assertSee('Budi Operasional')
            ->assertDontSee('Sari Gudang')
            ->callAction('approve', arguments: ['type' => 'leave', 'id' => $leave->id])
            ->assertNotified('Pengajuan Berhasil Disetujui');

        $this->assertSame(2, (int) $leave->fresh()->current_step);

        $this->actingAs($this->bod, 'web');
        Livewire::test(ApprovalSaya::class)
            ->assertSee('Budi Operasional')
            ->callAction('reject', data: ['reason' => ''], arguments: ['type' => 'leave', 'id' => $leave->id])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame('pending', $leave->fresh()->status, 'A rejection without a reason is refused.');

        Livewire::test(ApprovalSaya::class)
            ->callAction('reject', data: ['reason' => 'Bentrok dengan audit akhir tahun'], arguments: ['type' => 'leave', 'id' => $leave->id])
            ->assertNotified('Pengajuan Ditolak');

        $this->assertSame('rejected', $leave->fresh()->status);
        $this->assertSame('Bentrok dengan audit akhir tahun', $leave->fresh()->rejection_reason);

        $this->actingAs($this->approverB, 'web');
        Livewire::test(ApprovalSaya::class)->assertDontSee('Budi Operasional');
    }

    public function test_aksi_setujui_di_daftar_cuti_web_hanya_tampil_untuk_pemegang_tahap_aktif(): void
    {
        $leave = $this->submitLeave($this->budi);

        $this->actingAs($this->bod, 'web');
        Livewire::test(ListLeaveRequests::class)
            ->assertCanSeeTableRecords([$leave])
            ->assertActionHidden(TestAction::make('approveStep')->table($leave))
            ->assertActionHidden(TestAction::make('rejectStep')->table($leave));

        $this->actingAs($this->approver, 'web');
        Livewire::test(ListLeaveRequests::class)
            ->assertActionVisible(TestAction::make('approveStep')->table($leave))
            ->callAction(TestAction::make('approveStep')->table($leave));

        $this->assertSame(2, (int) $leave->fresh()->current_step);

        $this->actingAs($this->bod, 'web');
        Livewire::test(ListLeaveRequests::class)
            ->assertActionVisible(TestAction::make('approveStep')->table($leave))
            ->callAction(TestAction::make('approveStep')->table($leave));

        $this->assertSame('approved', $leave->fresh()->status);

        $this->actingAs($this->approverB, 'web');
        Livewire::test(ListLeaveRequests::class)->assertCanNotSeeTableRecords([$leave]);
    }

    public function test_approver_dan_bod_tidak_bisa_mengelola_data_operasional_klien(): void
    {
        $leave = $this->submitLeave($this->budi);

        foreach ([$this->approver, $this->bod] as $user) {
            Sanctum::actingAs($user);

            $this->getJson('/api/v1/client/overview')->assertForbidden();
            $this->getJson('/api/v1/client/accounts')->assertForbidden();
            $this->postJson('/api/v1/client/accounts', ['name' => 'Akun Baru', 'roles' => ['Employee']])->assertForbidden();
            $this->getJson('/api/v1/master/employees')->assertForbidden();
            $this->getJson('/api/v1/master/approval-flows')->assertForbidden();
            $this->getJson('/api/v1/team/roster')->assertForbidden();
            $this->putJson("/api/v1/leave-requests/{$leave->id}", ['reason' => 'Diubah penyetuju'])->assertNotFound();
            $this->deleteJson("/api/v1/leave-requests/{$leave->id}")->assertNotFound();

            $this->actingAs($user, 'web');
            $this->assertTrue(ApprovalSaya::canAccess());
            $this->assertFalse(LeaveRequestResource::canEdit($leave), 'An approver decides on a request, never edits it.');
            $this->assertFalse(LeaveRequestResource::canDelete($leave));

            foreach ([EmployeeResource::class, UserResource::class, ApprovalFlowResource::class, CompanyResource::class, TenantResource::class, AttendanceResource::class, ShiftScheduleResource::class] as $resource) {
                $this->assertFalse($resource::canViewAny(), "{$resource} is not for Approver or BOD.");
            }

            $this->get(EmployeeResource::getUrl('index'))->assertForbidden();
            $this->get(UserResource::getUrl('index'))->assertForbidden();
            $this->get(ApprovalFlowResource::getUrl('index'))->assertForbidden();
        }

        $this->assertSame('Izin keluarga', $leave->fresh()->reason);
        $this->assertNotNull($leave->fresh());
    }

    public function test_api_proses_menolak_tipe_tidak_dikenal_data_hilang_dan_pengajuan_selesai(): void
    {
        $leave = $this->submitLeave($this->budi);

        $this->process($this->approver, 'cuti', $leave->id, 'approved')->assertUnprocessable();
        $this->process($this->approver, 'leave', 999999, 'approved')->assertNotFound();
        $this->process($this->approver, 'leave', $leave->id, 'maybe')->assertUnprocessable()->assertJsonValidationErrors('action');

        $this->process($this->approver, 'leave-requests', $leave->id, 'rejected', 'Kuota tim penuh')->assertOk();
        $this->process($this->approver, 'leave', $leave->id, 'approved')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Pengajuan ini sudah tidak berstatus pending.');
    }

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company([
            'name' => $name,
            'code' => $code,
            'latitude' => self::OFFICE_LATITUDE,
            'longitude' => self::OFFICE_LONGITUDE,
            'is_active' => true,
        ]);
        $company->tenant_id = $tenant->id;
        $company->save();

        $company->policy()->update([
            'require_gps' => true,
            'geofence_radius_meters' => 100,
            'work_start_time' => '08:00:00',
            'late_tolerance_minutes' => 15,
        ]);

        return $company;
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $companyIds
     */
    private function account(Tenant $tenant, array $roles, string $name, DataScope $scope, array $companyIds = []): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id])->save();
        $user->assignRole($roles);
        $user->grantDataAccess($scope, companyIds: $companyIds);

        return $user;
    }

    private function worker(Company $company, Division $division, string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'is_active' => true]);
        $user->forceFill(['tenant_id' => $company->tenant_id])->save();
        $user->assignRole($role);

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

    /**
     * @param  list<string>  $roles
     */
    private function flow(Company $company, string $requestType, array $roles): void
    {
        foreach ($roles as $index => $role) {
            ApprovalFlow::create([
                'company_id' => $company->id,
                'request_type' => $requestType,
                'step_number' => $index + 1,
                'step_order' => $index + 1,
                'name' => "Persetujuan {$role}",
                'approver_type' => 'role',
                'approver_role' => $role,
            ]);
        }
    }

    private function submitLeave(User $worker, string $start = '2026-11-23', ?string $end = null): LeaveRequest
    {
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type' => 'permission',
            'start_date' => $start,
            'end_date' => $end ?? $start,
            'reason' => 'Izin keluarga',
        ])->assertCreated();

        return LeaveRequest::findOrFail($response->json('data.id'));
    }

    private function submitOvertime(User $worker): OvertimeRequest
    {
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/v1/overtime-requests', [
            'date' => '2026-11-16',
            'start_time' => '17:00',
            'end_time' => '20:00',
            'reason' => 'Stock opname akhir bulan',
        ])->assertCreated();

        return OvertimeRequest::findOrFail($response->json('data.id'));
    }

    private function submitCorrection(User $worker, string $date = '2026-11-13'): AttendanceCorrection
    {
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/v1/attendance-corrections', [
            'date' => $date,
            'corrected_check_in' => "{$date} 08:00:00",
            'corrected_check_out' => "{$date} 17:00:00",
            'reason' => 'Lupa absen, HP mati',
        ])->assertCreated();

        return AttendanceCorrection::findOrFail($response->json('data.id'));
    }

    private function submitResignation(User $worker): Resignation
    {
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/v1/resignations', [
            'resignation_date' => '2026-11-16',
            'last_working_day' => '2026-12-16',
            'reason' => 'Melanjutkan studi',
        ])->assertCreated();

        return Resignation::findOrFail($response->json('data.id'));
    }

    private function checkInOutsideGeofence(User $worker): Attendance
    {
        Sanctum::actingAs($worker);

        $response = $this->postJson('/api/v1/attendance/check-in', [
            'latitude' => -6.2000000,
            'longitude' => 106.8500000,
        ])->assertOk()
            ->assertJsonPath('requires_approval', true)
            ->assertJsonPath('attendance.status', AttendanceStatus::PendingApproval->value);

        return Attendance::findOrFail($response->json('attendance.id'));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function pending(User $user): array
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/approvals/pending')->assertOk()->json();
    }

    private function process(User $user, string $type, int $id, string $action, ?string $note = null): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->putJson("/api/v1/approvals/{$type}/{$id}/process", array_filter([
            'action' => $action,
            'rejection_note' => $note,
        ]));
    }
}
