<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Resources\TenantResource\Pages\ListTenants;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use App\Services\DataAccessService;
use App\Services\TenantOnboardingService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for the account and security findings of the daily-ops QA pass: adding a PIC from
 * the Klien table, signing an account out of the app after a password change, and refusing API
 * tokens of accounts whose Klien is inactive.
 */
class AccountSecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private Tenant $klienA;

    private Tenant $klienB;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->superadmin = User::factory()->create(['is_active' => true]);
        $this->superadmin->assignRole('Superadmin');
        $this->superadmin->grantDataAccess(DataScope::All);

        $this->klienA = Tenant::factory()->create(['name' => 'Klien Alfa', 'code' => 'ALFA', 'is_active' => true]);
        $this->klienB = Tenant::factory()->create(['name' => 'Klien Beta', 'code' => 'BETA', 'is_active' => true]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $roles
     */
    private function account(Tenant $tenant, array $roles, string $password = 'lama-123'): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->forceFill(['tenant_id' => $tenant->id, 'password' => $password])->save();
        $user->assignRole($roles);
        $user->grantDataAccess(DataScope::Own);

        return $user->fresh();
    }

    private function company(Tenant $tenant, string $name, string $code): Company
    {
        $company = new Company(['name' => $name, 'code' => $code, 'is_active' => true]);
        $company->tenant_id = $tenant->id;
        $company->save();
        app(ApprovalFlowService::class)->createDefaultFlows($company);

        return $company;
    }

    private function login(string $email, string $password): string
    {
        $this->forgetAuth();

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()
            ->json('token');
    }

    private function forgetAuth(): void
    {
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->app['session']->flush();
        $this->flushHeaders();
        DataAccessService::flush();
    }

    private function asToken(string $token): static
    {
        $this->forgetAuth();

        return $this->withToken($token);
    }

    // ── C4: Tambah PIC ───────────────────────────────────────────────────────

    public function test_tambah_pic_dari_tabel_klien_membuat_akun_pic(): void
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
    }

    public function test_tambah_pic_dari_tabel_klien_menolak_email_yang_sudah_dipakai(): void
    {
        $taken = $this->account($this->klienA, ['Employee']);
        $this->actingAs($this->superadmin);

        Livewire::test(ListTenants::class)
            ->callAction(TestAction::make('addPic')->table($this->klienB), [
                'pic_name' => 'Pak Dodi Lagi',
                'pic_email' => $taken->email,
            ])
            ->assertHasFormErrors(['pic_email' => 'unique']);

        $this->assertSame(1, User::query()->where('email', $taken->email)->count());
    }

    public function test_buat_klien_dengan_pic_baru_dan_tolak_email_pic_yang_sudah_dipakai(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Grup Gamma',
                'code' => 'GAMMA',
                'is_active' => true,
                'pic_name' => 'Bu Rina',
                'pic_email' => 'rina@gamma.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::query()->where('code', 'GAMMA')->firstOrFail();
        $this->assertSame($tenant->id, User::query()->where('email', 'rina@gamma.test')->value('tenant_id'));

        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Grup Delta',
                'code' => 'DELTA',
                'is_active' => true,
                'pic_name' => 'Bu Rina Lagi',
                'pic_email' => 'rina@gamma.test',
            ])
            ->call('create')
            ->assertHasFormErrors(['pic_email' => 'unique']);

        $this->assertFalse(Tenant::query()->where('code', 'DELTA')->exists());
    }

    // ── C6: password change signs the account out of the app ─────────────────

    public function test_ganti_password_lewat_model_mencabut_semua_token(): void
    {
        $crew = $this->account($this->klienA, ['Employee']);
        $crew->createToken('ponsel');
        $crew->createToken('tablet');

        $crew->update(['password' => 'baru-456']);

        $this->assertSame(0, $crew->tokens()->count());
    }

    public function test_ubah_data_lain_tidak_mencabut_token(): void
    {
        $crew = $this->account($this->klienA, ['Employee']);
        $crew->createToken('ponsel');

        $crew->update(['name' => 'Nama Baru']);

        $this->assertSame(1, $crew->tokens()->count());
    }

    public function test_ganti_password_dari_panel_mencabut_token_aplikasi(): void
    {
        $approver = $this->account($this->klienA, ['Approver']);
        $token = $this->login($approver->email, 'lama-123');

        $this->forgetAuth();
        $this->actingAs($this->superadmin);
        Livewire::test(EditUser::class, ['record' => $approver->getRouteKey()])
            ->fillForm(['password' => 'baru-456'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('baru-456', $approver->fresh()->password));
        $this->assertSame(0, $approver->tokens()->count());
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_reset_password_dari_aplikasi_tetap_mencabut_token_dan_password_baru_bisa_login(): void
    {
        $pic = app(TenantOnboardingService::class)->createPic($this->klienA, 'PIC Alfa', 'pic@alfa.test')['user']->fresh();
        $this->company($this->klienA, 'PT Alfa', 'ALF');
        $crew = $this->account($this->klienA, ['Employee']);
        $old = $this->login($crew->email, 'lama-123');

        $this->forgetAuth();
        $this->actingAs($pic);
        $newPassword = (string) $this->postJson("/api/v1/client/accounts/{$crew->id}/reset-password")
            ->assertOk()
            ->json('credentials.password');

        $this->assertSame(0, $crew->tokens()->count());
        $this->asToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $fresh = $this->login($crew->email, $newPassword);
        $this->asToken($fresh)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_login_biasa_tidak_mencabut_token_perangkat_lain(): void
    {
        $crew = $this->account($this->klienA, ['Employee']);
        $phone = $this->login($crew->email, 'lama-123');
        $tablet = $this->login($crew->email, 'lama-123');

        $this->assertSame(2, $crew->tokens()->count());
        $this->asToken($phone)->getJson('/api/v1/auth/me')->assertOk();
        $this->asToken($tablet)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_rehash_otomatis_saat_login_tidak_mencabut_token(): void
    {
        $crew = $this->account($this->klienA, ['Employee']);
        $phone = $this->login($crew->email, 'lama-123');

        // A hash made with other bcrypt rounds than the configured ones is rehashed on the next login.
        $oldHash = password_hash('lama-123', PASSWORD_BCRYPT, ['cost' => 5]);
        DB::table('users')->where('id', $crew->id)->update(['password' => $oldHash]);
        $this->assertTrue(Hash::needsRehash($oldHash));

        $tablet = $this->login($crew->email, 'lama-123');

        $this->assertNotSame($oldHash, $crew->fresh()->password, 'The login rehashed the password.');
        $this->assertTrue(Hash::check('lama-123', $crew->fresh()->password));
        $this->assertSame(2, $crew->tokens()->count());
        $this->asToken($phone)->getJson('/api/v1/auth/me')->assertOk();
        $this->asToken($tablet)->getJson('/api/v1/auth/me')->assertOk();

        // Afterwards a real password change still signs the account out.
        $crew->fresh()->update(['password' => 'baru-456']);
        $this->assertSame(0, $crew->tokens()->count());
    }

    // ── Sanctum: inactive Klien ──────────────────────────────────────────────

    public function test_token_akun_klien_nonaktif_ditolak(): void
    {
        $crew = $this->account($this->klienA, ['Employee']);
        $token = $this->login($crew->email, 'lama-123');
        $this->asToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->klienA->update(['is_active' => false]);

        $this->asToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_akun_nonaktif_ditolak_dan_klien_lain_tetap_jalan(): void
    {
        $crewA = $this->account($this->klienA, ['Employee']);
        $crewB = $this->account($this->klienB, ['Employee']);
        $tokenA = $this->login($crewA->email, 'lama-123');
        $tokenB = $this->login($crewB->email, 'lama-123');

        $crewA->forceFill(['is_active' => false])->save();

        $this->asToken($tokenA)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asToken($tokenB)->getJson('/api/v1/auth/me')->assertOk();
    }
}
