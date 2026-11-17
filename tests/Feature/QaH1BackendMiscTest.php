<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression tests for the QA staging cluster H1 (backend misc): division deletion guard, login and
 * validation language, freelancer institution and the OneSignal app id per environment.
 */
class QaH1BackendMiscTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'Employee', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'CV Uji', 'code' => 'UJI', 'is_active' => true]);
    }

    public function test_a_division_with_sub_divisions_cannot_be_deleted(): void
    {
        $parent = Division::create(['company_id' => $this->company->id, 'name' => 'Sales', 'is_active' => true]);
        $child = Division::create(['company_id' => $this->company->id, 'parent_id' => $parent->id, 'name' => 'Brand X', 'is_active' => true]);

        $this->assertStringContainsString('sub-divisi', (string) $parent->deletionBlockedReason());
        $this->assertFalse($parent->delete());
        $this->assertSame($parent->id, $child->fresh()->parent_id);
    }

    public function test_a_division_granted_as_data_access_cannot_be_deleted(): void
    {
        $division = Division::create(['company_id' => $this->company->id, 'name' => 'Finance', 'is_active' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Divisions, divisionIds: [$division->id]);

        $this->assertStringContainsString('akses data', (string) $division->deletionBlockedReason());
        $this->assertFalse($division->delete());
        $this->assertSame(1, $admin->fresh()->accessibleDivisions()->count());
    }

    public function test_a_division_with_its_own_approval_flow_cannot_be_deleted(): void
    {
        $division = Division::create(['company_id' => $this->company->id, 'name' => 'Ops', 'is_active' => true]);
        ApprovalFlow::create(['company_id' => $this->company->id, 'division_id' => $division->id, 'request_type' => 'leave', 'step_number' => 1, 'step_order' => 1, 'name' => 'Approver', 'approver_type' => 'role', 'approver_role' => 'Admin']);

        $this->assertStringContainsString('alur persetujuan', (string) $division->deletionBlockedReason());
        $this->assertFalse($division->delete());
    }

    public function test_an_unused_leaf_division_can_still_be_deleted(): void
    {
        $division = Division::create(['company_id' => $this->company->id, 'name' => 'Kosong', 'is_active' => true]);

        $this->assertNull($division->deletionBlockedReason());
        $this->assertTrue($division->delete());
    }

    public function test_magic_link_answers_known_and_unknown_addresses_alike_while_the_mailer_fails(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.from.address' => 'noreply@contoh.id']);
        User::factory()->create(['email' => 'terdaftar@contoh.id']);
        $this->partialMock(EmailOtpService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('issue')->andThrow(new RuntimeException('Gagal mengirim email login.'));
        });

        $known = $this->postJson('/api/v1/auth/magic-link/request', ['email' => 'terdaftar@contoh.id']);
        $unknown = $this->postJson('/api/v1/auth/magic-link/request', ['email' => 'tidak-ada@contoh.id']);

        $known->assertStatus(503)->assertJsonMissingPath('challenge_id');
        $this->assertSame([$known->status(), $known->json('message')], [$unknown->status(), $unknown->json('message')]);
        $unknown->assertJsonMissingPath('challenge_id');
    }

    public function test_wrong_credentials_answer_in_indonesian(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id', 'password' => bcrypt('Benar-123')]);

        $this->postJson('/api/v1/auth/login', ['email' => 'pekerja@contoh.id', 'password' => 'Salah-123'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Email atau kata sandi salah.']);
    }

    public function test_validation_messages_are_indonesian_under_the_id_locale(): void
    {
        app()->setLocale('id');
        config(['app.fallback_locale' => 'id']);

        $validator = Validator::make(['email' => 'budi', 'password' => ''], ['email' => ['required', 'email'], 'password' => ['required']]);

        $this->assertSame('Email harus berupa alamat email yang valid.', $validator->errors()->first('email'));
        $this->assertSame('Kata sandi wajib diisi.', $validator->errors()->first('password'));
        $this->assertSame('Email atau kata sandi salah.', __('auth.failed'));
    }

    public function test_freelancer_without_institution_gets_null_not_an_invented_value(): void
    {
        $division = Division::create(['company_id' => $this->company->id, 'name' => 'Crew', 'is_active' => true]);
        $user = User::factory()->create();
        $user->assignRole('Freelancer');
        Freelancer::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $division->id, 'full_name' => 'Pekerja Lepas', 'status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.freelancer.institution', null);

        $user->freelancer->update(['institution' => 'Vendor Kebersihan']);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.freelancer.institution', 'Vendor Kebersihan');
    }

    public function test_onesignal_only_falls_back_to_the_production_app_in_production(): void
    {
        $this->assertNull($this->oneSignalConfig('staging')['app_id']);
        $this->assertNull($this->oneSignalConfig('staging')['web_app_id']);
        $this->assertNull($this->oneSignalConfig('local')['app_id']);
        $this->assertSame('37e96a62-f9fc-45d5-8159-1f079b3f80f5', $this->oneSignalConfig('production')['app_id']);
        $this->assertSame('37e96a62-f9fc-45d5-8159-1f079b3f80f5', $this->oneSignalConfig('production')['web_app_id']);
        $this->assertSame('staging-app', $this->oneSignalConfig('staging', 'staging-app')['app_id']);
    }

    /**
     * Load config/services.php under the given environment variables and return its OneSignal block.
     *
     * @return array<string, mixed>
     */
    private function oneSignalConfig(string $appEnv, ?string $appId = null): array
    {
        $variables = ['APP_ENV' => $appEnv, 'ONESIGNAL_APP_ID' => $appId, 'ONESIGNAL_WEB_APP_ID' => null];
        $previous = [];

        foreach ($variables as $name => $value) {
            $previous[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $this->setEnvironmentVariable($name, $value);
        }

        try {
            return (require config_path('services.php'))['onesignal'];
        } finally {
            foreach ($previous as $name => [$server, $env, $process]) {
                $this->setEnvironmentVariable($name, $server ?? $env ?? ($process === false ? null : $process));
            }
        }
    }

    private function setEnvironmentVariable(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }
}
