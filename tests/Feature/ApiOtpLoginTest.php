<?php

namespace Tests\Feature;

use App\Mail\EmailLoginMail;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = 'https://wag.contoh.test/api/v1/messages';

    private const REQUEST_URL = '/api/v1/auth/otp/request';

    private const VERIFY_URL = '/api/v1/auth/otp/verify';

    private const LINK_REQUEST_URL = '/api/v1/auth/magic-link/request';

    private const LINK_VERIFY_URL = '/api/v1/auth/magic-link/verify';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp_gateway.url' => 'https://wag.contoh.test',
            'services.whatsapp_gateway.token' => 'test-token',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'noreply@contoh.id',
        ]);
        Http::fake([self::GATEWAY => Http::response(['ok' => true])]);
        Mail::fake();
        Role::findOrCreate('Employee', 'web');
    }

    // --- WhatsApp -------------------------------------------------------------------------------

    public function test_worker_logs_in_with_the_code_sent_to_their_whatsapp(): void
    {
        $user = $this->makeEmployeeUser('081234567890');

        $requested = $this->requestWhatsAppCode('0812-3456-7890')
            ->assertOk()
            ->assertJsonPath('channel', 'whatsapp')
            ->assertJsonStructure(['message', 'challenge_id', 'expires_in']);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::GATEWAY
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['recipient']['value'] === '6281234567890');

        $verified = $this->verify($requested->json('challenge_id'), $this->sentWhatsAppCode())
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.employee.nip', 'NIP-'.$user->id)
            ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'email', 'roles', 'permissions', 'employee', 'intern', 'freelancer']]);

        $this->withToken($verified->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_whatsapp_code_can_only_be_used_once(): void
    {
        $this->makeEmployeeUser('081234567890');
        $challengeId = $this->requestWhatsAppCode('081234567890')->json('challenge_id');
        $code = $this->sentWhatsAppCode();

        $this->verify($challengeId, $code)->assertOk();
        $this->verify($challengeId, $code)->assertStatus(401);
    }

    public function test_unknown_number_still_gets_a_code_but_cannot_log_in(): void
    {
        $challengeId = $this->requestWhatsAppCode('089999999999')->assertOk()->json('challenge_id');

        Http::assertSentCount(1);

        $this->verify($challengeId, $this->sentWhatsAppCode())
            ->assertStatus(401)
            ->assertJsonPath('message', 'Nomor belum terdaftar untuk login. Hubungi administrator.');
    }

    public function test_inactive_account_is_refused_with_403_after_a_valid_whatsapp_code(): void
    {
        $user = $this->makeEmployeeUser('081234567890');
        $user->forceFill(['is_active' => false])->save();

        $challengeId = $this->requestWhatsAppCode('081234567890')->assertOk()->json('challenge_id');

        $this->verify($challengeId, $this->sentWhatsAppCode())
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda tidak aktif. Hubungi admin untuk mengaktifkannya kembali.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_whatsapp_codes_are_limited(): void
    {
        $this->makeEmployeeUser('081234567890');
        $challengeId = $this->requestWhatsAppCode('081234567890')->json('challenge_id');
        $code = $this->sentWhatsAppCode();
        $wrongCode = $code === '111111' ? '222222' : '111111';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->verify($challengeId, $wrongCode)
                ->assertStatus(401)
                ->assertJsonPath('message', 'Kode OTP tidak valid atau sudah kedaluwarsa.');
        }

        $this->verify($challengeId, $wrongCode)->assertStatus(429)->assertHeader('Retry-After');
        $this->verify($challengeId, $code)->assertStatus(429);
    }

    public function test_expired_whatsapp_code_is_refused(): void
    {
        $this->makeEmployeeUser('081234567890');
        $challengeId = $this->requestWhatsAppCode('081234567890')->json('challenge_id');
        $code = $this->sentWhatsAppCode();

        $this->travel(6)->minutes();

        $this->verify($challengeId, $code)->assertStatus(401);
    }

    public function test_requesting_a_new_whatsapp_code_retires_the_old_one(): void
    {
        $this->makeEmployeeUser('081234567890');
        $oldChallengeId = $this->requestWhatsAppCode('081234567890')->json('challenge_id');
        $oldCode = $this->sentWhatsAppCode();

        $newChallengeId = $this->requestWhatsAppCode('081234567890')->assertOk()->json('challenge_id');
        $newCode = $this->sentWhatsAppCode();

        $this->assertNotSame($oldChallengeId, $newChallengeId);
        $this->verify($oldChallengeId, $oldCode)->assertStatus(401);
        $this->verify($newChallengeId, $newCode)->assertOk();
    }

    public function test_invalid_phone_number_is_rejected(): void
    {
        $this->requestWhatsAppCode('12')->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->postJson(self::REQUEST_URL, ['channel' => 'whatsapp'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
        Http::assertNothingSent();
    }

    public function test_whatsapp_is_unavailable_when_the_gateway_is_not_configured(): void
    {
        config(['services.whatsapp_gateway.token' => null]);

        $this->requestWhatsAppCode('081234567890')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_whatsapp_gateway_failure_is_reported_and_no_challenge_is_kept(): void
    {
        $this->makeEmployeeUser('081234567890');
        config(['services.whatsapp_gateway.url' => 'https://wag-mati.contoh.test']);
        Http::fake(['https://wag-mati.contoh.test/*' => Http::response(['message' => 'down'], 503)]);

        $this->requestWhatsAppCode('081234567890')
            ->assertStatus(503)
            ->assertJsonMissingPath('challenge_id');
    }

    // --- Email ----------------------------------------------------------------------------------

    public function test_user_logs_in_with_the_emailed_code(): void
    {
        $user = User::factory()->create(['email' => 'pekerja@contoh.id', 'email_verified_at' => null]);
        $user->assignRole('Employee');

        $challengeId = $this->requestEmailCode('Pekerja@Contoh.id')
            ->assertOk()
            ->assertJsonPath('channel', 'email')
            ->assertJsonStructure(['message', 'challenge_id', 'expires_in'])
            ->json('challenge_id');

        Mail::assertSent(EmailLoginMail::class, fn (EmailLoginMail $mail): bool => $mail->hasTo('pekerja@contoh.id'));

        $this->verify($challengeId, $this->sentEmailCode())
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.roles.0', 'Employee')
            ->assertJsonStructure(['token', 'token_type', 'user']);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_unknown_email_gets_a_challenge_but_no_email_and_cannot_log_in(): void
    {
        $challengeId = $this->requestEmailCode('tidak-ada@contoh.id')
            ->assertOk()
            ->assertJsonStructure(['challenge_id', 'expires_in'])
            ->json('challenge_id');

        Mail::assertNothingSent();

        $this->verify($challengeId, '123456')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Kode OTP tidak valid atau sudah kedaluwarsa.');
    }

    public function test_inactive_account_is_refused_with_403_after_a_valid_email_code(): void
    {
        $user = User::factory()->create(['email' => 'pekerja@contoh.id', 'is_active' => false]);

        $challengeId = $this->requestEmailCode('pekerja@contoh.id')->assertOk()->json('challenge_id');

        $this->verify($challengeId, $this->sentEmailCode())
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda tidak aktif. Hubungi admin untuk mengaktifkannya kembali.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_email_code_stops_working_after_five_wrong_attempts(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id']);
        $challengeId = $this->requestEmailCode('pekerja@contoh.id')->json('challenge_id');
        $code = $this->sentEmailCode();
        $wrongCode = $code === '111111' ? '222222' : '111111';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->verify($challengeId, $wrongCode)->assertStatus(401);
        }

        $this->verify($challengeId, $code)->assertStatus(401);
    }

    public function test_expired_email_code_is_refused(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id']);
        $challengeId = $this->requestEmailCode('pekerja@contoh.id')->json('challenge_id');
        $code = $this->sentEmailCode();

        $this->travel(11)->minutes();

        $this->verify($challengeId, $code)->assertStatus(401);
    }

    public function test_email_requests_for_one_address_are_limited(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->requestEmailCode('pekerja@contoh.id')->assertOk();
        }

        $this->requestEmailCode('pekerja@contoh.id')->assertStatus(429)->assertHeader('Retry-After');
        Mail::assertSentCount(3);
    }

    public function test_email_is_unavailable_when_no_real_mailer_is_configured(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.log.transport' => 'log']);

        $this->requestEmailCode('pekerja@contoh.id')->assertStatus(503);
        Mail::assertNothingSent();
    }

    // --- Email link -----------------------------------------------------------------------------

    public function test_user_logs_in_with_the_link_emailed_to_the_app(): void
    {
        $user = User::factory()->create(['email' => 'pekerja@contoh.id', 'email_verified_at' => null]);
        $user->assignRole('Employee');

        $challengeId = $this->requestAppLink('Pekerja@Contoh.id')
            ->assertOk()
            ->assertJsonStructure(['message', 'challenge_id', 'expires_in'])
            ->json('challenge_id');

        Mail::assertSent(EmailLoginMail::class, fn (EmailLoginMail $mail): bool => $mail->hasTo('pekerja@contoh.id')
            && $mail->otp === null
            && $mail->envelope()->subject === 'Link masuk '.config('app.name')
            && str_starts_with($mail->loginUrl, url('app/email-login').'/'));

        $verified = $this->verifyAppLink($challengeId, $this->sentAppLinkToken())
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['token', 'user']);

        $this->withToken($verified->json('token'))->getJson('/api/v1/auth/me')->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_app_link_can_only_be_used_once(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id']);
        $challengeId = $this->requestAppLink('pekerja@contoh.id')->json('challenge_id');
        $token = $this->sentAppLinkToken();

        $this->verifyAppLink($challengeId, $token)->assertOk();
        $this->verifyAppLink($challengeId, $token)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Link masuk tidak valid atau sudah kedaluwarsa. Minta link baru dari aplikasi.');
    }

    public function test_app_link_only_works_with_the_challenge_it_was_sent_for(): void
    {
        User::factory()->create(['email' => 'korban@contoh.id']);
        User::factory()->create(['email' => 'lain@contoh.id']);

        $otherChallengeId = $this->requestAppLink('lain@contoh.id')->json('challenge_id');
        $victimChallengeId = $this->requestAppLink('korban@contoh.id')->json('challenge_id');
        $victimToken = $this->sentAppLinkToken();

        $this->verifyAppLink($otherChallengeId, $victimToken)->assertStatus(401);
        $this->verifyAppLink('otp_'.str_repeat('a', 48), $victimToken)->assertStatus(401);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The failed tries did not use the link up for the device that asked for it.
        $this->verifyAppLink($victimChallengeId, $victimToken)->assertOk();
    }

    public function test_link_and_code_challenges_are_not_interchangeable(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id']);

        $linkChallengeId = $this->requestAppLink('pekerja@contoh.id')->json('challenge_id');
        $this->verify($linkChallengeId, '123456')->assertStatus(401);

        $codeChallengeId = $this->requestEmailCode('pekerja@contoh.id')->json('challenge_id');
        $codeEmail = Mail::sent(EmailLoginMail::class)->last();
        $this->assertNotNull($codeEmail->otp);
        $codeToken = basename(parse_url($codeEmail->loginUrl, PHP_URL_PATH));

        $this->verifyAppLink($codeChallengeId, $codeToken)->assertStatus(401);
    }

    public function test_unknown_email_gets_a_link_challenge_but_no_email(): void
    {
        $this->requestAppLink('tidak-ada@contoh.id')
            ->assertOk()
            ->assertJsonStructure(['challenge_id', 'expires_in']);

        Mail::assertNothingSent();
    }

    public function test_inactive_account_is_refused_with_403_after_a_valid_app_link(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id', 'is_active' => false]);
        $challengeId = $this->requestAppLink('pekerja@contoh.id')->json('challenge_id');

        $this->verifyAppLink($challengeId, $this->sentAppLinkToken())
            ->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda tidak aktif. Hubungi admin untuk mengaktifkannya kembali.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_app_link_is_refused(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id']);
        $challengeId = $this->requestAppLink('pekerja@contoh.id')->json('challenge_id');
        $token = $this->sentAppLinkToken();

        $this->travel(11)->minutes();

        $this->verifyAppLink($challengeId, $token)->assertStatus(401);
    }

    public function test_app_link_is_unavailable_when_no_real_mailer_is_configured(): void
    {
        config(['mail.default' => 'log', 'mail.mailers.log.transport' => 'log']);

        $this->requestAppLink('pekerja@contoh.id')->assertStatus(503);
        Mail::assertNothingSent();
    }

    public function test_app_link_bodies_are_validated(): void
    {
        $this->postJson(self::LINK_REQUEST_URL, ['email' => 'bukan-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'Alamat email tidak valid.']);
        $this->postJson(self::LINK_VERIFY_URL, ['challenge_id' => 'otp_x', 'token' => 'pendek'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
        $this->postJson(self::LINK_VERIFY_URL, ['token' => str_repeat('a', 64)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['challenge_id']);
    }

    public function test_link_page_opens_the_app_without_using_the_token(): void
    {
        config(['services.mobile_app.deep_link' => 'ontime://']);
        User::factory()->create(['email' => 'pekerja@contoh.id']);
        $challengeId = $this->requestAppLink('pekerja@contoh.id')->json('challenge_id');
        $token = $this->sentAppLinkToken();

        $this->get(Mail::sent(EmailLoginMail::class)->last()->loginUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('ontime://email-login?token='.$token, escape: false);

        $this->verifyAppLink($challengeId, $token)->assertOk();
    }

    public function test_link_page_follows_the_configured_deep_link(): void
    {
        config(['services.mobile_app.deep_link' => 'exp://127.0.0.1:8081/--/']);
        $token = str_repeat('a', 64);

        $this->get(route('app-login.link', ['token' => $token]))
            ->assertOk()
            ->assertSee('exp://127.0.0.1:8081/--/email-login?token='.$token, escape: false);
    }

    // --- Common ---------------------------------------------------------------------------------

    public function test_request_body_is_validated(): void
    {
        $this->postJson(self::REQUEST_URL, ['channel' => 'sms', 'phone' => '081234567890'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);
        $this->postJson(self::REQUEST_URL, ['channel' => 'email', 'email' => 'bukan-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
        $this->postJson(self::VERIFY_URL, ['challenge_id' => 'otp_x', 'code' => '12ab56'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
        $this->postJson(self::VERIFY_URL, ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['challenge_id']);
    }

    public function test_unknown_challenge_is_refused(): void
    {
        $this->verify('otp_'.str_repeat('a', 48), '123456')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Kode OTP tidak valid atau sudah kedaluwarsa.');
    }

    public function test_otp_requests_per_address_are_limited(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->requestEmailCode("orang{$attempt}@contoh.id")->assertOk();
        }

        $this->requestEmailCode('orang11@contoh.id')->assertStatus(429);
    }

    public function test_otp_verifications_per_address_are_limited(): void
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->verify('otp_'.str_repeat('a', 48), '123456')->assertStatus(401);
        }

        $this->verify('otp_'.str_repeat('a', 48), '123456')->assertStatus(429);
    }

    // --- Helpers --------------------------------------------------------------------------------

    private function requestWhatsAppCode(string $phone): TestResponse
    {
        return $this->postJson(self::REQUEST_URL, ['channel' => 'whatsapp', 'phone' => $phone]);
    }

    private function requestEmailCode(string $email): TestResponse
    {
        return $this->postJson(self::REQUEST_URL, ['channel' => 'email', 'email' => $email]);
    }

    private function requestAppLink(string $email): TestResponse
    {
        return $this->postJson(self::LINK_REQUEST_URL, ['email' => $email]);
    }

    private function verifyAppLink(string $challengeId, string $token): TestResponse
    {
        return $this->postJson(self::LINK_VERIFY_URL, ['challenge_id' => $challengeId, 'token' => $token]);
    }

    /**
     * The token at the end of the link in the last login email.
     */
    private function sentAppLinkToken(): string
    {
        return basename((string) parse_url(Mail::sent(EmailLoginMail::class)->last()->loginUrl, PHP_URL_PATH));
    }

    private function verify(string $challengeId, string $code): TestResponse
    {
        return $this->postJson(self::VERIFY_URL, ['challenge_id' => $challengeId, 'code' => $code]);
    }

    /**
     * The 6-digit code from the last WhatsApp message sent through the gateway.
     */
    private function sentWhatsAppCode(): string
    {
        $request = collect(Http::recorded())->last()[0];
        preg_match('/\*(\d{6})\*/', (string) $request['message']['text'], $matches);

        return $matches[1] ?? '';
    }

    private function sentEmailCode(): string
    {
        return Mail::sent(EmailLoginMail::class)->last()->otp;
    }

    private function makeEmployeeUser(string $phone): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $company = Company::firstOrCreate(['code' => 'TOP'], ['name' => 'CV Top', 'is_active' => true]);
        $division = Division::firstOrCreate(['company_id' => $company->id, 'name' => 'SALES'], ['is_active' => true]);

        Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff'])->id,
            'nip' => 'NIP-'.$user->id,
            'full_name' => 'Pekerja '.$user->id,
            'phone' => $phone,
            'join_date' => '2025-01-06',
            'status' => 'active',
        ]);

        return $user;
    }
}
