<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\Channels\OneSignalChannel;
use App\Services\ApprovalFlowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OneSignalPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const PUSH_URL = 'https://api.onesignal.com/notifications?c=push';

    private Company $company;

    private Division $division;

    private User $employeeUser;

    private User $approverUser;

    private User $bodUser;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);

        $this->company = Company::create(['name' => 'PT Push Test', 'code' => 'PUSH', 'is_active' => true]);
        CompanyPolicy::create([
            'company_id' => $this->company->id,
            'late_tolerance_minutes' => 15,
            'require_photo' => false,
            'require_gps' => false,
            'annual_leave_quota' => 12,
            'work_start_time' => '08:00:00',
            'work_end_time' => '17:00:00',
        ]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Divisi Push', 'code' => 'PUSHDIV', 'is_active' => true]);

        $jobLevel = JobLevel::firstOrCreate(['name' => 'Staff', 'level_order' => 1]);
        $jobTitle = JobTitle::firstOrCreate(['name' => 'Programmer']);

        $this->employeeUser = User::factory()->create(['name' => 'Pekerja Push']);
        $this->employeeUser->assignRole('Employee');
        Employee::create([
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'PUSH.001',
            'full_name' => 'Pekerja Push',
            'join_date' => now()->toDateString(),
            'status' => 'permanent',
        ]);

        $this->approverUser = User::factory()->create(['name' => 'Approver Push']);
        $this->approverUser->assignRole('Approver');
        $this->grantDataAccessTo($this->approverUser, $this->company->id, $this->division->id);

        $this->bodUser = User::factory()->create(['name' => 'BOD Push']);
        $this->bodUser->assignRole('BOD');
        $this->grantDataAccessTo($this->bodUser, $this->company->id);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([[1, 'Approver'], [2, 'BOD']] as [$order, $role]) {
            ApprovalFlow::create([
                'company_id' => $this->company->id,
                'request_type' => 'leave',
                'step_number' => $order,
                'step_order' => $order,
                'name' => "{$role} leave",
                'approver_type' => 'role',
                'approver_role' => $role,
            ]);
        }

        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
    }

    /**
     * Stub the OneSignal API. Only the first stub registered for a URL answers, so each test calls
     * this once, before anything sends a push.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function fakeOneSignal(?array $body = null, int $status = 200): void
    {
        Http::fake([self::PUSH_URL => Http::response($body ?? ['id' => 'notification-1'], $status)]);
    }

    private function submitLeaveRequest(): LeaveRequest
    {
        Sanctum::actingAs($this->employeeUser);

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type' => 'sick',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'reason' => 'Sakit demam',
        ])->assertCreated();

        return LeaveRequest::query()->findOrFail($response->json('data.id'));
    }

    /**
     * @return list<Request>
     */
    private function pushRequests(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => $request->url() === self::PUSH_URL)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function targets(Request $request): array
    {
        return $request->data()['include_aliases']['external_id'];
    }

    public function test_approvers_of_the_first_stage_get_a_push_when_a_request_is_submitted(): void
    {
        $this->fakeOneSignal();
        $leave = $this->submitLeaveRequest();

        $requests = $this->pushRequests();
        $this->assertCount(1, $requests);

        $payload = $requests[0]->data();
        $this->assertSame([(string) $this->approverUser->id], $this->targets($requests[0]));
        $this->assertSame('app-id', $payload['app_id']);
        $this->assertSame('push', $payload['target_channel']);
        $this->assertSame('Pengajuan baru menunggu persetujuan Anda', $payload['headings']['en']);
        $this->assertSame('Pengajuan Cuti / Izin dari Pekerja Push menunggu persetujuan Anda.', $payload['contents']['id']);
        $this->assertSame([
            'kind' => 'approval_requested',
            'request_type' => 'leave',
            'request_id' => $leave->id,
            'step_order' => 1,
        ], $payload['data']);
        $this->assertTrue($requests[0]->hasHeader('Authorization', 'Key rest-key'));
    }

    public function test_the_next_stage_approver_gets_a_push_and_the_requester_gets_the_final_outcome(): void
    {
        $this->fakeOneSignal();
        $leave = $this->submitLeaveRequest();

        $service = app(ApprovalFlowService::class);
        $service->approveStep($leave, $this->approverUser);

        $requests = $this->pushRequests();
        $this->assertCount(2, $requests);
        $this->assertSame([(string) $this->bodUser->id], $this->targets($requests[1]));
        $this->assertSame('Pengajuan menunggu persetujuan tahap 2', $requests[1]->data()['headings']['en']);
        $this->assertSame(2, $requests[1]->data()['data']['step_order']);

        $service->approveStep($leave->refresh(), $this->bodUser);

        $requests = $this->pushRequests();
        $this->assertCount(3, $requests);
        $this->assertSame([(string) $this->employeeUser->id], $this->targets($requests[2]));
        $this->assertSame('Pengajuan Cuti / Izin disetujui', $requests[2]->data()['headings']['en']);
        $this->assertSame([
            'kind' => 'approval_resolved',
            'request_type' => 'leave',
            'request_id' => $leave->id,
            'status' => 'approved',
        ], $requests[2]->data()['data']);
    }

    public function test_the_requester_gets_a_push_with_the_reason_when_the_request_is_rejected(): void
    {
        $this->fakeOneSignal();
        $leave = $this->submitLeaveRequest();

        Sanctum::actingAs($this->approverUser);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", [
            'action' => 'rejected',
            'rejection_note' => 'Kuota tim sudah habis',
        ])->assertOk();

        $requests = $this->pushRequests();
        $this->assertCount(2, $requests);
        $payload = $requests[1]->data();
        $this->assertSame([(string) $this->employeeUser->id], $this->targets($requests[1]));
        $this->assertSame('Pengajuan Cuti / Izin ditolak', $payload['headings']['en']);
        $this->assertSame('Pengajuan Cuti / Izin Anda ditolak: Kuota tim sudah habis', $payload['contents']['en']);
        $this->assertSame('rejected', $payload['data']['status']);
    }

    public function test_nothing_is_sent_without_a_rest_api_key(): void
    {
        $this->fakeOneSignal();
        config(['services.onesignal.rest_api_key' => null]);

        $leave = $this->submitLeaveRequest();
        app(ApprovalFlowService::class)->rejectStep($leave, $this->approverUser, 'Tidak');

        Http::assertNothingSent();
    }

    public function test_the_notification_is_queued_and_uses_the_onesignal_channel(): void
    {
        Notification::fake();

        $this->submitLeaveRequest();

        Notification::assertSentTo($this->approverUser, ApprovalRequestedNotification::class, function (ApprovalRequestedNotification $notification, array $channels): bool {
            return $channels === [OneSignalChannel::class]
                && $notification instanceof ShouldQueue;
        });
        Notification::assertNotSentTo($this->employeeUser, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->bodUser, ApprovalRequestedNotification::class);
    }

    public function test_a_user_without_a_subscribed_device_is_not_an_error(): void
    {
        $this->fakeOneSignal(['id' => '', 'errors' => ['invalid_aliases' => ['external_id' => ['x']]]]);

        $this->submitLeaveRequest();

        $this->assertCount(1, $this->pushRequests());
    }

    public function test_a_failed_onesignal_request_throws_so_the_queued_job_is_retried(): void
    {
        $this->fakeOneSignal(['errors' => ['down']], 500);
        $this->withoutExceptionHandling();

        $this->expectException(RequestException::class);

        $this->submitLeaveRequest();
    }
}
