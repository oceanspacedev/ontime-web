<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\CompanyPolicy;
use App\Models\DeviceToken;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\Channels\ExpoPushChannel;
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

class ExpoPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const PUSH_URL = 'https://exp.host/--/api/v2/push/send';

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

        DeviceToken::query()->create(['user_id' => $this->employeeUser->id, 'token' => 'ExponentPushToken[employee]', 'platform' => 'android']);
        DeviceToken::query()->create(['user_id' => $this->approverUser->id, 'token' => 'ExponentPushToken[approver-phone]', 'platform' => 'ios']);
        DeviceToken::query()->create(['user_id' => $this->approverUser->id, 'token' => 'ExponentPushToken[approver-tablet]', 'platform' => 'android']);
    }

    /**
     * Stub the Expo Push API. Only the first stub registered for a URL answers, so each test calls
     * this once, before anything sends a push.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function fakeExpo(?array $body = null, int $status = 200): void
    {
        Http::fake([self::PUSH_URL => Http::response($body ?? ['data' => [['status' => 'ok', 'id' => 'ticket-1']]], $status)]);
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

    public function test_approvers_of_the_first_stage_get_a_push_when_a_request_is_submitted(): void
    {
        $this->fakeExpo();
        $leave = $this->submitLeaveRequest();

        $requests = $this->pushRequests();
        $this->assertCount(1, $requests);

        $payload = $requests[0]->data();
        $this->assertEqualsCanonicalizing(['ExponentPushToken[approver-phone]', 'ExponentPushToken[approver-tablet]'], $payload['to']);
        $this->assertSame('Pengajuan baru menunggu persetujuan Anda', $payload['title']);
        $this->assertSame('Pengajuan Cuti / Izin dari Pekerja Push menunggu persetujuan Anda.', $payload['body']);
        $this->assertSame('default', $payload['sound']);
        $this->assertSame('default', $payload['channelId']);
        $this->assertSame([
            'kind' => 'approval_requested',
            'request_type' => 'leave',
            'request_id' => $leave->id,
            'step_order' => 1,
        ], $payload['data']);
        $this->assertTrue($requests[0]->hasHeader('Accept', 'application/json'));
        $this->assertFalse($requests[0]->hasHeader('Authorization'));
    }

    public function test_the_next_stage_approver_gets_a_push_and_the_requester_gets_the_final_outcome(): void
    {
        $this->fakeExpo();
        DeviceToken::query()->create(['user_id' => $this->bodUser->id, 'token' => 'ExponentPushToken[bod]', 'platform' => 'ios']);

        $leave = $this->submitLeaveRequest();

        $service = app(ApprovalFlowService::class);
        $service->approveStep($leave, $this->approverUser);

        $requests = $this->pushRequests();
        $this->assertCount(2, $requests);
        $stageTwo = $requests[1]->data();
        $this->assertSame(['ExponentPushToken[bod]'], $stageTwo['to']);
        $this->assertSame('Pengajuan menunggu persetujuan tahap 2', $stageTwo['title']);
        $this->assertSame(2, $stageTwo['data']['step_order']);

        $service->approveStep($leave->refresh(), $this->bodUser);

        $requests = $this->pushRequests();
        $this->assertCount(3, $requests);
        $resolved = $requests[2]->data();
        $this->assertSame(['ExponentPushToken[employee]'], $resolved['to']);
        $this->assertSame('Pengajuan Cuti / Izin disetujui', $resolved['title']);
        $this->assertSame([
            'kind' => 'approval_resolved',
            'request_type' => 'leave',
            'request_id' => $leave->id,
            'status' => 'approved',
        ], $resolved['data']);
    }

    public function test_the_requester_gets_a_push_with_the_reason_when_the_request_is_rejected(): void
    {
        $this->fakeExpo();
        $leave = $this->submitLeaveRequest();

        Sanctum::actingAs($this->approverUser);
        $this->putJson("/api/v1/approvals/leave/{$leave->id}/process", [
            'action' => 'rejected',
            'rejection_note' => 'Kuota tim sudah habis',
        ])->assertOk();

        $requests = $this->pushRequests();
        $this->assertCount(2, $requests);
        $payload = $requests[1]->data();
        $this->assertSame(['ExponentPushToken[employee]'], $payload['to']);
        $this->assertSame('Pengajuan Cuti / Izin ditolak', $payload['title']);
        $this->assertSame('Pengajuan Cuti / Izin Anda ditolak: Kuota tim sudah habis', $payload['body']);
        $this->assertSame('rejected', $payload['data']['status']);
    }

    public function test_nothing_is_sent_to_users_without_a_registered_device(): void
    {
        $this->fakeExpo();
        DeviceToken::query()->delete();

        $leave = $this->submitLeaveRequest();
        app(ApprovalFlowService::class)->rejectStep($leave, $this->approverUser, 'Tidak');

        Http::assertNothingSent();
    }

    public function test_the_notification_is_queued_and_uses_the_expo_channel(): void
    {
        Notification::fake();

        $this->submitLeaveRequest();

        Notification::assertSentTo($this->approverUser, ApprovalRequestedNotification::class, function (ApprovalRequestedNotification $notification, array $channels): bool {
            return $channels === [ExpoPushChannel::class]
                && $notification instanceof ShouldQueue;
        });
        Notification::assertNotSentTo($this->employeeUser, ApprovalRequestedNotification::class);
        Notification::assertNotSentTo($this->bodUser, ApprovalRequestedNotification::class);
    }

    public function test_tokens_that_expo_reports_as_unregistered_are_deleted(): void
    {
        $this->fakeExpo(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']],
        ]]);

        $this->submitLeaveRequest();

        $sentTo = $this->pushRequests()[0]->data()['to'];
        $this->assertDatabaseHas('device_tokens', ['token' => $sentTo[0]]);
        $this->assertDatabaseMissing('device_tokens', ['token' => $sentTo[1]]);
    }

    public function test_the_expo_access_token_is_sent_when_configured(): void
    {
        $this->fakeExpo();
        config(['services.expo.access_token' => 'expo-secret']);

        $this->submitLeaveRequest();

        $this->assertTrue($this->pushRequests()[0]->hasHeader('Authorization', 'Bearer expo-secret'));
    }

    public function test_a_failed_expo_request_throws_so_the_queued_job_is_retried(): void
    {
        $this->fakeExpo(['errors' => [['code' => 'INTERNAL_SERVER_ERROR', 'message' => 'down']]], 500);
        $this->withoutExceptionHandling();

        $this->expectException(RequestException::class);

        $this->submitLeaveRequest();
    }
}
