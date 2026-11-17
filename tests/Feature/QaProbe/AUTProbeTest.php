<?php

namespace Tests\Feature\QaProbe;

use App\Enums\DataScope;
use App\Mail\EmailLoginMail;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;
use Tests\TestCase;

/**
 * QA-STAGING.md AUT-001..AUT-008 executed locally against the API and the web panel.
 *
 * Every test asserts the oracle of the catalog case it is named after. Cases that need a real OTP
 * gateway, real mail provider or staging infrastructure are covered here only with faked transports.
 */
class AUTProbeTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = 'https://wag.contoh.test/api/v1/messages';

    private const MONDAY = '2026-11-16';

    private Tenant $tenant;

    private Company $company;

    private Division $division;

    /** Body of the last message handed to the failing mail transport. */
    public static ?string $failedMailBody = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Notification::fake();
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([self::GATEWAY => Http::response(['ok' => true])]);
        Mail::fake();

        config([
            'filesystems.default' => 'public',
            'services.face.driver' => 'basic',
            'services.whatsapp_gateway.url' => 'https://wag.contoh.test',
            'services.whatsapp_gateway.token' => 'test-token',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'noreply@contoh.id',
        ]);

        $this->travelTo(Carbon::parse(self::MONDAY.' 07:50:00'));

        $this->tenant = Tenant::factory()->create(['name' => 'Klien QA', 'is_active' => true]);
        $this->company = $this->makeCompany($this->tenant, 'PT QA', 'QAA');
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Outlet QA', 'code' => 'OQ', 'is_active' => true]);

        self::$failedMailBody = null;
    }

    // ── AUT-001 ───────────────────────────────────────────────────────────────

    /**
     * @see AUT-001
     */
    public function test_aut_001_every_persona_logs_in_gets_own_identity_and_wrong_password_gets_no_token(): void
    {
        $personas = [
            'PIC' => $this->makeAccount($this->tenant, ['Admin', 'PIC'], DataScope::All),
            'Admin' => $this->makeAccount($this->tenant, ['Admin'], DataScope::All),
            'Koordinator' => $this->makeAccount($this->tenant, ['Koordinator'], DataScope::Companies, [$this->company->id]),
            'Approver' => $this->makeAccount($this->tenant, ['Approver'], DataScope::Companies, [$this->company->id]),
            'BOD' => $this->makeAccount($this->tenant, ['BOD']),
            'Employee' => User::findOrFail($this->makeEmployee('Karyawan QA')->user_id),
            'Intern' => User::findOrFail($this->makeIntern()->user_id),
            'Freelancer' => User::findOrFail($this->makeFreelancer()->user_id),
        ];

        foreach ($personas as $role => $account) {
            $token = $this->passwordLogin($account->email, 'password')->assertOk()->json('token');

            $me = $this->asToken($token)->getJson('/api/v1/auth/me')
                ->assertOk()
                ->assertJsonPath('user.id', $account->id);
            $this->assertContains($role, $me->json('user.roles'), "{$role} keeps its role in /me");
            $this->assertSame($role === 'PIC', (bool) $me->json('user.access.client_admin.is_pic'), "{$role} PIC flag");

            $workerBlock = match ($role) {
                'Employee' => 'employee',
                'Intern' => 'intern',
                'Freelancer' => 'freelancer',
                default => null,
            };
            foreach (['employee', 'intern', 'freelancer'] as $block) {
                $block === $workerBlock
                    ? $this->assertNotNull($me->json("user.{$block}"), "{$role} has its {$block} profile")
                    : $this->assertNull($me->json("user.{$block}"), "{$role} has no {$block} profile");
            }

            $this->asToken($token)->postJson('/api/v1/auth/logout')->assertOk();
            $this->asToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

            $tokensBefore = $account->tokens()->count();
            $this->passwordLogin($account->email, 'salah-sekali')->assertStatus(401)->assertJsonMissingPath('token');
            $this->assertSame($tokensBefore, $account->tokens()->count());
        }
    }

    /**
     * @see AUT-001
     */
    public function test_aut_001_management_account_without_worker_profile_gets_no_attendance_of_others(): void
    {
        $worker = $this->makeEmployee('Karyawan QA');
        $attendance = Attendance::create([
            'employee_id' => $worker->id,
            'date' => self::MONDAY,
            'check_in' => '07:45:00',
            'status' => 'on_time',
        ]);
        $bod = $this->makeAccount($this->tenant, ['BOD']);
        $token = $this->passwordLogin($bod->email, 'password')->assertOk()->json('token');

        $this->asToken($token)->getJson('/api/v1/attendances')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->asToken($token)->getJson("/api/v1/attendances/{$attendance->id}")->assertForbidden();
        $this->asToken($token)->getJson('/api/v1/attendance/today')->assertStatus(422)->assertJsonMissingPath('attendance');

        $checkIn = $this->asToken($token)->post('/api/v1/attendance/check-in', [], ['Accept' => 'application/json']);
        $this->assertGreaterThanOrEqual(400, $checkIn->status(), 'An account without a worker profile cannot check in.');
        $this->assertSame(1, Attendance::count());
        $this->assertNull($attendance->fresh()->check_out);
    }

    // ── AUT-002 ───────────────────────────────────────────────────────────────

    /**
     * @see AUT-002
     */
    public function test_aut_002_email_code_of_another_account_does_not_open_the_challenge(): void
    {
        $userA = User::factory()->create(['email' => 'Pekerja.A@Contoh.id', 'tenant_id' => $this->tenant->id]);
        $userB = User::factory()->create(['email' => 'pekerja.b@contoh.id', 'tenant_id' => $this->tenant->id]);

        $challengeA = $this->requestEmailCode('  PEKERJA.a@contoh.ID ')->assertOk()->json('challenge_id');
        Mail::assertSent(EmailLoginMail::class, fn (EmailLoginMail $mail): bool => $mail->hasTo('pekerja.a@contoh.id'));
        $codeA = $this->lastEmailCode();

        $challengeB = $this->requestEmailCode('pekerja.b@contoh.id')->assertOk()->json('challenge_id');
        $codeB = $this->lastEmailCode();

        if ($codeA !== $codeB) {
            $this->verifyCode($challengeA, $codeB)->assertStatus(401)->assertJsonMissingPath('token');
            $this->verifyCode($challengeB, $codeA)->assertStatus(401)->assertJsonMissingPath('token');
        }

        $this->verifyCode($challengeA, $codeA)->assertOk()->assertJsonPath('user.id', $userA->id);
        $this->verifyCode($challengeB, $codeB)->assertOk()->assertJsonPath('user.id', $userB->id);
    }

    /**
     * @see AUT-002
     */
    public function test_aut_002_whatsapp_code_of_another_number_does_not_open_the_challenge(): void
    {
        $userA = User::findOrFail($this->makeEmployee('Pekerja A', ['phone' => '081200000001'])->user_id);
        $userB = User::findOrFail($this->makeEmployee('Pekerja B', ['phone' => '081200000002'])->user_id);

        $challengeA = $this->requestWhatsAppCode('+62 812-0000-0001')->assertOk()->json('challenge_id');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::GATEWAY && $request['recipient']['value'] === '6281200000001');
        $codeA = $this->lastWhatsAppCode();

        $challengeB = $this->requestWhatsAppCode('081200000002')->assertOk()->json('challenge_id');
        $codeB = $this->lastWhatsAppCode();

        if ($codeA !== $codeB) {
            $this->verifyCode($challengeA, $codeB)->assertStatus(401)->assertJsonMissingPath('token');
        }

        $this->verifyCode($challengeA, $codeA)->assertOk()->assertJsonPath('user.id', $userA->id);
        $this->verifyCode($challengeB, $codeB)->assertOk()->assertJsonPath('user.id', $userB->id);
    }

    /**
     * A number HR typed with the usual formatting ("+62", spaces, dashes) is the same number the
     * worker types on the login screen: the code sent to it must log that worker in.
     *
     * @see AUT-002
     */
    public function test_aut_002_worker_whose_phone_was_saved_with_formatting_logs_in_with_whatsapp(): void
    {
        $saved = [
            '081200000011' => '0812-0000-0011',
            '081200000012' => '+62 812 0000 0012',
            '081200000013' => '+6281200000013',
        ];

        $failures = [];
        foreach ($saved as $typed => $stored) {
            $user = User::findOrFail($this->makeEmployee('Pekerja '.$typed, ['phone' => $stored])->user_id);

            $challengeId = $this->requestWhatsAppCode($typed)->assertOk()->json('challenge_id');
            $verified = $this->verifyCode($challengeId, $this->lastWhatsAppCode());

            if ($verified->status() !== 200 || $verified->json('user.id') !== $user->id) {
                $failures[] = "stored '{$stored}', typed '{$typed}': HTTP {$verified->status()} ".$verified->json('message');
            }
        }

        $this->assertSame([], $failures, 'Workers whose phone was saved with formatting cannot log in with WhatsApp OTP.');
    }

    // ── AUT-003 ───────────────────────────────────────────────────────────────

    /**
     * @see AUT-003
     */
    public function test_aut_003_email_resend_retires_the_previous_code_and_a_code_is_consumed_once(): void
    {
        $user = User::factory()->create(['email' => 'pekerja@contoh.id', 'tenant_id' => $this->tenant->id]);

        $firstChallenge = $this->requestEmailCode('pekerja@contoh.id')->assertOk()->json('challenge_id');
        $firstCode = $this->lastEmailCode();
        $wrongCode = $firstCode === '111111' ? '222222' : '111111';

        $this->verifyCode($firstChallenge, $wrongCode)->assertStatus(401)->assertJsonMissingPath('token');

        $secondChallenge = $this->requestEmailCode('pekerja@contoh.id')->assertOk()->json('challenge_id');
        $secondCode = $this->lastEmailCode();

        if ($firstCode !== $secondCode) {
            $this->verifyCode($secondChallenge, $firstCode)->assertStatus(401)->assertJsonMissingPath('token');
            $this->verifyCode($firstChallenge, $firstCode)->assertStatus(401)->assertJsonMissingPath('token');
        }

        $this->verifyCode($secondChallenge, $secondCode)->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame(1, $user->tokens()->count());

        // Replay of the consumed code, on its own challenge and on the older one.
        $this->verifyCode($secondChallenge, $secondCode)->assertStatus(401)->assertJsonMissingPath('token');
        $this->verifyCode($firstChallenge, $secondCode)->assertStatus(401)->assertJsonMissingPath('token');
        $this->assertSame(1, $user->tokens()->count());
    }

    /**
     * @see AUT-003
     */
    public function test_aut_003_whatsapp_challenge_past_expires_in_gives_no_token(): void
    {
        $this->makeEmployeeWithPhone('081200000021');

        $requested = $this->requestWhatsAppCode('081200000021')->assertOk();
        $code = $this->lastWhatsAppCode();

        $this->travel((int) $requested->json('expires_in') + 1)->seconds();

        $this->verifyCode($requested->json('challenge_id'), $code)->assertStatus(401)->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // ── AUT-004 ───────────────────────────────────────────────────────────────

    /**
     * @see AUT-004
     */
    public function test_aut_004_request_stage_answers_the_same_for_known_and_unknown_destinations(): void
    {
        User::factory()->create(['email' => 'terdaftar@contoh.id', 'tenant_id' => $this->tenant->id]);
        $this->makeEmployeeWithPhone('081200000031');

        $known = $this->requestEmailCode('terdaftar@contoh.id');
        $unknown = $this->requestEmailCode('tidak-terdaftar@contoh.id');
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(array_keys($known->json()), array_keys($unknown->json()));
        $this->assertSame($known->json('expires_in'), $unknown->json('expires_in'));

        $knownPhone = $this->requestWhatsAppCode('081200000031');
        $unknownPhone = $this->requestWhatsAppCode('081200000039');
        $this->assertSame($knownPhone->status(), $unknownPhone->status());
        $this->assertSame($knownPhone->json('message'), $unknownPhone->json('message'));
        $this->assertSame(array_keys($knownPhone->json()), array_keys($unknownPhone->json()));

        $this->verifyCode($unknown->json('challenge_id'), '123456')->assertStatus(401)->assertJsonMissingPath('token');
        $this->verifyCode($unknownPhone->json('challenge_id'), $this->lastWhatsAppCode())->assertStatus(401)->assertJsonMissingPath('token');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * While the mail provider is failing, the request step must still not tell a registered address
     * from an unregistered one.
     *
     * @see AUT-004
     * @see AUT-006
     */
    public function test_aut_004_mail_provider_failure_does_not_reveal_which_address_has_an_account(): void
    {
        User::factory()->create(['email' => 'terdaftar@contoh.id', 'tenant_id' => $this->tenant->id]);
        $this->useFailingMailer();

        $known = $this->requestEmailCode('terdaftar@contoh.id');
        $unknown = $this->requestEmailCode('tidak-terdaftar@contoh.id');

        $this->assertSame(
            [$unknown->status(), $unknown->json('message')],
            [$known->status(), $known->json('message')],
            'Known and unknown addresses get different answers while the mail provider fails.',
        );
    }

    // ── AUT-005 ───────────────────────────────────────────────────────────────

    /**
     * @see AUT-005
     */
    public function test_aut_005_five_minute_identity_limit_rejects_the_fourth_request_and_recovers(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id', 'tenant_id' => $this->tenant->id]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->requestEmailCode('pekerja@contoh.id')->assertOk();
        }

        $limited = $this->requestEmailCode('pekerja@contoh.id')->assertStatus(429)->assertJsonMissingPath('challenge_id');
        $retryAfter = (int) $limited->json('retry_after');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(300, $retryAfter);
        Mail::assertSentCount(3);

        $this->travel($retryAfter + 1)->seconds();

        $this->requestEmailCode('pekerja@contoh.id')->assertOk()->assertJsonStructure(['challenge_id']);
        Mail::assertSentCount(4);
    }

    /**
     * @see AUT-005
     */
    public function test_aut_005_hourly_identity_limit_rejects_the_eleventh_request_and_recovers(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id', 'tenant_id' => $this->tenant->id]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->requestEmailCode('pekerja@contoh.id')->assertOk();
            $this->travel(301)->seconds();
        }

        $this->requestEmailCode('pekerja@contoh.id')->assertStatus(429)->assertHeader('Retry-After');
        Mail::assertSentCount(10);

        $this->travel(3600)->seconds();
        $this->requestEmailCode('pekerja@contoh.id')->assertOk();
        Mail::assertSentCount(11);
    }

    // ── AUT-006 ───────────────────────────────────────────────────────────────

    /**
     * @see AUT-006
     */
    public function test_aut_006_mail_provider_failure_gives_no_token_no_code_in_log_and_recovers(): void
    {
        $user = User::factory()->create(['email' => 'pekerja@contoh.id', 'tenant_id' => $this->tenant->id]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });

        $this->useFailingMailer();
        $failed = $this->requestEmailCode('pekerja@contoh.id')->assertStatus(503)->assertJsonMissingPath('challenge_id');
        $this->assertStringNotContainsString('sudah dikirim', (string) $failed->json('message'), 'A failed send must not claim the code was sent.');
        $this->assertNotNull(self::$failedMailBody, 'The provider was really called.');
        preg_match('/\b(\d{6})\b/', (string) self::$failedMailBody, $matches);
        $failedCode = $matches[1] ?? null;
        $this->assertNotNull($failedCode);

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($failedCode, $line, 'The OTP is written to the log.');
            $this->assertStringNotContainsString('pekerja@contoh.id', $line, 'The full address is written to the log.');
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Provider recovers.
        $this->useFakeMailer();
        $challengeId = $this->requestEmailCode('pekerja@contoh.id')->assertOk()->json('challenge_id');
        $this->verifyCode($challengeId, $this->lastEmailCode())->assertOk()->assertJsonPath('user.id', $user->id);
    }

    /**
     * @see AUT-006
     */
    public function test_aut_006_whatsapp_gateway_timeout_gives_no_challenge_and_recovers(): void
    {
        $user = $this->makeEmployeeWithPhone('081200000061');

        config(['services.whatsapp_gateway.url' => 'https://wag-mati.contoh.test']);
        Http::fake(['https://wag-mati.contoh.test/*' => fn () => throw new ConnectionException('timeout')]);
        $this->requestWhatsAppCode('081200000061')->assertStatus(503)->assertJsonMissingPath('challenge_id');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Gateway back.
        config(['services.whatsapp_gateway.url' => 'https://wag.contoh.test']);
        $this->travel(61)->seconds();
        $requested = $this->requestWhatsAppCode('081200000061')->assertOk();
        $this->verifyCode($requested->json('challenge_id'), $this->lastWhatsAppCode())->assertOk()->assertJsonPath('user.id', $user->id);
    }

    // ── AUT-007 ───────────────────────────────────────────────────────────────

    /**
     * @see AUT-007
     */
    public function test_aut_007_pic_password_reset_revokes_both_devices_and_old_password_fails(): void
    {
        $pic = $this->makeAccount($this->tenant, ['Admin', 'PIC'], DataScope::All);
        $crew = User::findOrFail($this->makeEmployee('Karyawan QA')->user_id);

        $phone = $this->passwordLogin($crew->email, 'password')->assertOk()->json('token');
        $tablet = $this->passwordLogin($crew->email, 'password')->assertOk()->json('token');
        $picToken = $this->passwordLogin($pic->email, 'password')->assertOk()->json('token');

        $newPassword = (string) $this->asToken($picToken)->postJson("/api/v1/client/accounts/{$crew->id}/reset-password")
            ->assertOk()
            ->json('credentials.password');

        $this->asToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken($tablet)->getJson('/api/v1/attendance/today')->assertUnauthorized();
        $this->passwordLogin($crew->email, 'password')->assertStatus(401);
        $this->passwordLogin($crew->email, $newPassword)->assertOk();
        $this->asToken($picToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    /**
     * @see AUT-007
     */
    public function test_aut_007_narrowed_scope_applies_to_the_next_request_of_an_open_session(): void
    {
        $otherCompany = $this->makeCompany($this->tenant, 'PT QA Dua', 'QAB');
        $otherDivision = Division::create(['company_id' => $otherCompany->id, 'name' => 'Outlet QA Dua', 'code' => 'OQ2', 'is_active' => true]);
        $inScope = $this->makeEmployee('Karyawan A1');
        $outOfScope = $this->makeEmployee('Karyawan A2', [], $otherCompany, $otherDivision);

        $inScopeRow = Attendance::create(['employee_id' => $inScope->id, 'date' => self::MONDAY, 'check_in' => '07:45:00', 'status' => 'on_time']);
        $outOfScopeRow = Attendance::create(['employee_id' => $outOfScope->id, 'date' => self::MONDAY, 'check_in' => '07:46:00', 'status' => 'on_time']);

        $admin = $this->makeAccount($this->tenant, ['Admin'], DataScope::All);
        $token = $this->passwordLogin($admin->email, 'password')->assertOk()->json('token');

        $this->asToken($token)->getJson("/api/v1/attendances/{$outOfScopeRow->id}")->assertOk();
        $this->asToken($token)->getJson('/api/v1/attendances')->assertOk()->assertJsonPath('pagination.total', 2);

        User::findOrFail($admin->id)->grantDataAccess(DataScope::Companies, companyIds: [$this->company->id]);

        $this->asToken($token)->getJson("/api/v1/attendances/{$outOfScopeRow->id}")->assertForbidden();
        $this->asToken($token)->getJson("/api/v1/attendances/{$inScopeRow->id}")->assertOk();
        $ids = collect($this->asToken($token)->getJson('/api/v1/attendances')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$inScopeRow->id], $ids);
    }

    // ── AUT-008 ───────────────────────────────────────────────────────────────

    /**
     * @see AUT-008
     */
    public function test_aut_008_deactivated_user_and_tenant_cannot_mutate_and_open_attendance_is_untouched(): void
    {
        $this->company->policy->update(['require_gps' => false, 'require_photo' => false]);
        $worker = $this->makeEmployee('Karyawan QA');
        $user = User::findOrFail($worker->user_id);
        $token = $this->passwordLogin($user->email, 'password')->assertOk()->json('token');

        $this->asToken($token)->post('/api/v1/attendance/check-in', [], ['Accept' => 'application/json'])->assertOk();
        $attendance = Attendance::where('employee_id', $worker->id)->sole();
        $snapshot = $this->attendanceState($attendance);

        // 1. Account switched off.
        $user->forceFill(['is_active' => false])->save();
        $this->travelTo(Carbon::parse(self::MONDAY.' 17:05:00'));

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken($token)->post('/api/v1/attendance/check-out', [], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->asToken($token)->postJson('/api/v1/leave-requests', ['leave_type' => 'annual', 'start_date' => '2026-11-20', 'end_date' => '2026-11-20', 'reason' => 'x'])->assertUnauthorized();
        $this->passwordLogin($user->email, 'password')->assertForbidden()->assertJsonMissingPath('token');
        $this->assertSame($snapshot, $this->attendanceState($attendance));

        // 2. Account back on, Klien switched off.
        $user->forceFill(['is_active' => true])->save();
        $this->tenant->update(['is_active' => false]);

        $this->asToken($token)->post('/api/v1/attendance/check-out', [], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->passwordLogin($user->email, 'password')->assertForbidden()->assertJsonMissingPath('token');

        // 3. New login through OTP.
        $challengeId = $this->requestEmailCode($user->email)->assertOk()->json('challenge_id');
        $this->verifyCode($challengeId, $this->lastEmailCode())->assertForbidden()->assertJsonMissingPath('token');

        $this->assertSame($snapshot, $this->attendanceState($attendance));
        $this->assertSame(0, $user->tokens()->count());
    }

    /**
     * @see AUT-008
     */
    public function test_aut_008_web_session_of_a_deactivated_account_loses_the_panel(): void
    {
        $user = User::findOrFail($this->makeEmployee('Karyawan QA')->user_id);

        $this->asWebSession($user)->get('/admin')->assertOk();

        $user->forceFill(['is_active' => false])->save();
        $this->asWebSession($user)->get('/admin')->assertForbidden();
    }

    /**
     * The selfie upload route of the panel camera sits outside the panel middleware: an account that
     * was switched off while its browser session is open must not keep storing photos there.
     *
     * @see AUT-008
     */
    public function test_aut_008_web_session_of_a_deactivated_account_cannot_upload_selfies(): void
    {
        $user = User::findOrFail($this->makeEmployee('Karyawan QA')->user_id);
        $user->forceFill(['is_active' => false])->save();

        $response = $this->asWebSession($user)->post('/upload-selfie', [
            'photo' => UploadedFile::fake()->image('selfie.jpg', 64, 64),
        ], ['Accept' => 'application/json']);

        $this->assertContains($response->status(), [401, 403], 'Deactivated account still uploads a selfie: HTTP '.$response->status());
        $this->assertSame([], Storage::disk('public')->allFiles('attendance-photos'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, string|null>
     */
    private function attendanceState(Attendance $attendance): array
    {
        $fresh = $attendance->fresh();

        return [
            'date' => $fresh->date?->toDateString(),
            'check_in' => $fresh->check_in?->toDateTimeString(),
            'check_out' => $fresh->check_out?->toDateTimeString(),
            'status' => $fresh->status?->value,
        ];
    }

    private function makeCompany(Tenant $tenant, string $name, string $code): Company
    {
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'code' => $code,
            'is_active' => true,
        ]);

        app(ApprovalFlowService::class)->createDefaultFlows($company);

        return $company;
    }

    /**
     * @param  list<string>  $roles
     * @param  list<int>  $companyIds
     */
    private function makeAccount(Tenant $tenant, array $roles, DataScope $scope = DataScope::Own, array $companyIds = []): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_active' => true]);
        $user->assignRole($roles);
        $user->grantDataAccess($scope, companyIds: $companyIds);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = [], ?Company $company = null, ?Division $division = null): Employee
    {
        $company ??= $this->company;
        $division ??= $this->division;
        $user = $this->makeAccount($company->tenant, ['Employee']);

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_number' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Crew'])->id,
            'nip' => 'EMP'.$user->id,
            'full_name' => $name,
            'join_date' => now()->subYear(),
            'status' => 'permanent',
            ...$overrides,
        ]);
    }

    private function makeEmployeeWithPhone(string $phone): User
    {
        return User::findOrFail($this->makeEmployee('Pekerja '.$phone, ['phone' => $phone])->user_id);
    }

    private function makeIntern(): Intern
    {
        $user = $this->makeAccount($this->tenant, ['Intern']);

        return Intern::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'nis' => 'NIS'.$user->id,
            'full_name' => 'Magang QA',
            'institution' => 'Universitas Uji',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(2),
            'status' => 'active',
        ]);
    }

    private function makeFreelancer(): Freelancer
    {
        $user = $this->makeAccount($this->tenant, ['Freelancer']);

        return Freelancer::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'freelancer_number' => 'FRL'.$user->id,
            'full_name' => 'Freelancer QA',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
        ]);
    }

    /**
     * Forget guards, session and headers so the next request is authenticated only by what it carries.
     */
    private function forgetAuth(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->flushHeaders();
        DataAccessService::flush();
    }

    private function asToken(string $token): static
    {
        $this->forgetAuth();

        return $this->withToken($token);
    }

    /**
     * A browser session of this account, reloaded from the database on every request like a real one.
     */
    private function asWebSession(User $user): static
    {
        $this->forgetAuth();

        return $this->withSession([Auth::guard('web')->getName() => $user->id]);
    }

    private function passwordLogin(string $email, string $password): TestResponse
    {
        $this->forgetAuth();

        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function requestEmailCode(string $email): TestResponse
    {
        $this->forgetAuth();

        return $this->postJson('/api/v1/auth/otp/request', ['channel' => 'email', 'email' => $email]);
    }

    private function requestWhatsAppCode(string $phone): TestResponse
    {
        $this->forgetAuth();

        return $this->postJson('/api/v1/auth/otp/request', ['channel' => 'whatsapp', 'phone' => $phone]);
    }

    private function verifyCode(string $challengeId, string $code): TestResponse
    {
        $this->forgetAuth();

        return $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challengeId, 'code' => $code]);
    }

    private function lastEmailCode(): string
    {
        return (string) Mail::sent(EmailLoginMail::class)->last()->otp;
    }

    private function lastWhatsAppCode(): string
    {
        $request = collect(Http::recorded())->last()[0];
        preg_match('/\*(\d{6})\*/', (string) $request['message']['text'], $matches);

        return $matches[1] ?? '';
    }

    /**
     * A real (non-fake) mailer whose transport fails like an unreachable provider, after recording
     * the message it was given.
     */
    private function useFailingMailer(): void
    {
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
        Mail::clearResolvedInstances();

        Mail::extend('qa-failing', fn (): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                $email = MessageConverter::toEmail($message->getOriginalMessage());
                AUTProbeTest::$failedMailBody = (string) ($email->getTextBody() ?? '').' '.(string) ($email->getHtmlBody() ?? '');

                throw new RuntimeException('provider down');
            }

            public function __toString(): string
            {
                return 'qa-failing://';
            }
        });

        config([
            'mail.default' => 'qa-failing',
            'mail.mailers.qa-failing' => ['transport' => 'qa-failing'],
        ]);
    }

    private function useFakeMailer(): void
    {
        config(['mail.default' => 'smtp']);
        $this->app->forgetInstance('mail.manager');
        $this->app->forgetInstance('mailer');
        Mail::clearResolvedInstances();
        Mail::fake();
    }
}
