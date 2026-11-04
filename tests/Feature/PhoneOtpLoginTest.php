<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\PhoneLogin;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhoneOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = 'https://wag.contoh.test/api/v1/messages';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp_gateway.url' => 'https://wag.contoh.test', 'services.whatsapp_gateway.token' => 'test-token']);
        Http::fake([self::GATEWAY => Http::response(['ok' => true])]);
        Role::findOrCreate('Employee', 'web');

        $this->user = $this->makeEmployeeUser('081234567890');
    }

    public function test_login_page_offers_whatsapp_only_when_the_gateway_is_configured(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Atau masuk dengan')->assertSee(route('phone-login'));

        config(['services.whatsapp_gateway.token' => null]);

        $this->get('/admin/login')->assertOk()->assertDontSee(route('phone-login'));
    }

    public function test_worker_logs_in_with_the_code_sent_to_their_whatsapp(): void
    {
        $page = Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '0812-3456-7890'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::GATEWAY
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['recipient']['value'] === '6281234567890'
            && $request['client_reference'] === 'ontime');

        $page->fillForm(['otp' => $this->sentCode()])
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_unknown_number_gets_a_code_step_but_cannot_log_in(): void
    {
        $page = $this->sendCodeTo('089999999999');

        $page->fillForm(['otp' => $this->sentCode()])
            ->call('verify')
            ->assertHasFormErrors(['phone']);

        $this->assertGuest();
    }

    public function test_number_shared_by_two_accounts_logs_nobody_in(): void
    {
        $this->makeEmployeeUser('081234567890');

        $page = $this->sendCodeTo('081234567890');

        $page->fillForm(['otp' => $this->sentCode()])->call('verify')->assertHasFormErrors(['phone']);
        $this->assertGuest();
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $this->user->update(['is_active' => false]);

        $page = $this->sendCodeTo('081234567890');

        $page->fillForm(['otp' => $this->sentCode()])->call('verify')->assertHasFormErrors(['phone']);
        $this->assertGuest();
    }

    public function test_wrong_codes_are_limited(): void
    {
        $page = $this->sendCodeTo('081234567890');
        $wrongCode = $this->sentCode() === '111111' ? '222222' : '111111';

        foreach (range(1, 5) as $ignored) {
            $page->fillForm(['otp' => $wrongCode])->call('verify')->assertHasFormErrors(['otp']);
        }

        $page->fillForm(['otp' => $this->sentCode()])->call('verify')->assertHasFormErrors(['otp']);
        $this->assertGuest();
    }

    public function test_gateway_failure_is_reported_and_no_code_is_kept(): void
    {
        config(['services.whatsapp_gateway.url' => 'https://wag-mati.contoh.test']);
        Http::fake(['https://wag-mati.contoh.test/*' => Http::response(['message' => 'down'], 503)]);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => '081234567890'])
            ->call('send')
            ->assertHasFormErrors(['phone'])
            ->assertSet('awaitingOtp', false);
    }

    public function test_the_code_is_not_written_to_the_log(): void
    {
        $this->sendCodeTo('081234567890');

        $log = storage_path('logs/laravel.log');
        $this->assertTrue(! is_file($log) || ! str_contains((string) file_get_contents($log), '*'.$this->sentCode().'*'));
    }

    private function sendCodeTo(string $phone): Testable
    {
        return Livewire::test(PhoneLogin::class)
            ->fillForm(['phone' => $phone])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true);
    }

    /**
     * The 6-digit code from the last WhatsApp message sent through the gateway.
     */
    private function sentCode(): string
    {
        $request = collect(Http::recorded())->last()[0];
        preg_match('/\*(\d{6})\*/', (string) $request['message']['text'], $matches);

        return $matches[1] ?? '';
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
