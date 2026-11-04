<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\EmailLogin;
use App\Mail\EmailLoginMail;
use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from.address' => 'noreply@contoh.id',
        ]);
        Mail::fake();
        Role::findOrCreate('Employee', 'web');

        $this->user = User::factory()->create(['email' => 'pekerja@contoh.id', 'email_verified_at' => null]);
        $this->user->assignRole('Employee');
    }

    public function test_login_page_offers_email_only_when_a_real_mailer_is_configured(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee(route('email-login'));

        config(['mail.default' => 'log', 'mail.mailers.log.transport' => 'log']);

        $this->get('/admin/login')->assertOk()->assertDontSee(route('email-login'));
    }

    public function test_user_logs_in_with_the_emailed_code(): void
    {
        $page = $this->requestCodeFor('Pekerja@Contoh.id');

        $page->fillForm(['otp' => $this->sentMail()->otp])
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($this->user);
        $this->assertNotNull($this->user->fresh()->email_verified_at, 'A code that reached the inbox proves the address.');
    }

    public function test_magic_link_logs_in_once(): void
    {
        $this->requestCodeFor('pekerja@contoh.id');
        $token = basename(parse_url($this->sentMail()->loginUrl, PHP_URL_PATH));

        Livewire::test(EmailLogin::class, ['token' => $token])
            ->call('consumeMagicLink')
            ->assertRedirect();
        $this->assertAuthenticatedAs($this->user);

        $this->app['auth']->guard()->logout();

        Livewire::test(EmailLogin::class, ['token' => $token])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);
        $this->assertGuest();
    }

    public function test_unknown_email_moves_on_without_sending_anything(): void
    {
        $this->requestCodeFor('tidak-ada@contoh.id');

        Mail::assertNothingSent();
        $this->assertSame(0, EmailOtp::count());
    }

    public function test_inactive_account_gets_no_email(): void
    {
        $this->user->update(['is_active' => false]);

        $this->requestCodeFor('pekerja@contoh.id');

        Mail::assertNothingSent();
    }

    public function test_code_stops_working_after_five_wrong_attempts(): void
    {
        $page = $this->requestCodeFor('pekerja@contoh.id');
        $code = $this->sentMail()->otp;
        $wrongCode = $code === '111111' ? '222222' : '111111';

        foreach (range(1, 5) as $ignored) {
            $page->fillForm(['otp' => $wrongCode])->call('verify')->assertHasFormErrors(['otp']);
        }

        $page->fillForm(['otp' => $code])->call('verify')->assertHasFormErrors(['otp']);
        $this->assertGuest();
    }

    public function test_requesting_a_new_code_retires_the_old_one(): void
    {
        $page = $this->requestCodeFor('pekerja@contoh.id');
        $oldCode = $this->sentMail()->otp;

        $page->call('changeEmail');
        $this->travel(301)->seconds();
        $page = $this->requestCodeFor('pekerja@contoh.id');

        if ($oldCode !== $this->sentMail()->otp) {
            $page->fillForm(['otp' => $oldCode])->call('verify')->assertHasFormErrors(['otp']);
        }

        $page->fillForm(['otp' => $this->sentMail()->otp])->call('verify')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_login_email_shows_the_code_and_the_link(): void
    {
        $html = (new EmailLoginMail('Pekerja', '123456', 'https://ontime.contoh.id/email-login/link/abc', 10))->render();

        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('https://ontime.contoh.id/email-login/link/abc', $html);
        $this->assertStringContainsString('berlaku 10 menit', $html);
    }

    private function requestCodeFor(string $email): Testable
    {
        return Livewire::test(EmailLogin::class)
            ->fillForm(['email' => $email])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true);
    }

    private function sentMail(): EmailLoginMail
    {
        return Mail::sent(EmailLoginMail::class)->last();
    }
}
