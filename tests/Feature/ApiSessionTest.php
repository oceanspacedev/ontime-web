<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The mobile app's session: password login limits, logout, and tokens that end after long disuse.
 */
class ApiSessionTest extends TestCase
{
    use RefreshDatabase;

    private const PUSH_TOKEN = 'ExponentPushToken[abcDEF123-_xyz]';

    // --- Password login limits -----------------------------------------------------------------

    public function test_password_guessing_for_one_email_is_limited(): void
    {
        User::factory()->create(['email' => 'pekerja@contoh.id', 'password' => Hash::make('rahasia123')]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login('pekerja@contoh.id', 'salah')->assertUnauthorized();
        }

        $this->login('pekerja@contoh.id', 'rahasia123')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Terlalu banyak percobaan masuk. Silakan coba lagi sebentar lagi.')
            ->assertJsonMissingPath('token');
    }

    public function test_login_limit_for_one_email_does_not_block_other_emails(): void
    {
        User::factory()->create(['email' => 'lain@contoh.id', 'password' => Hash::make('rahasia123')]);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->login('korban@contoh.id', 'salah');
        }

        $this->login('lain@contoh.id', 'rahasia123')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_login_limit_ignores_email_case_and_spaces(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login('pekerja@contoh.id', 'salah');
        }

        $this->login('  Pekerja@Contoh.ID ', 'salah')->assertStatus(429);
    }

    public function test_login_is_limited_per_address_across_emails(): void
    {
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->login("orang{$attempt}@contoh.id", 'salah')->assertUnauthorized();
        }

        $this->login('orang21@contoh.id', 'salah')->assertStatus(429);
    }

    // --- Logout ---------------------------------------------------------------------------------

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('auth-token')->plainTextToken;
        $tablet = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($phone)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        $this->assertTokenRejected($phone);
        $this->assertTokenAccepted($tablet);
    }

    public function test_logout_with_push_token_stops_pushes_to_this_device(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth-token')->plainTextToken;
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => self::PUSH_TOKEN, 'platform' => 'ios']);
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[otherDevice]', 'platform' => 'android']);

        $this->withToken($token)->postJson('/api/v1/auth/logout', ['push_token' => self::PUSH_TOKEN])->assertOk();

        $this->assertDatabaseMissing('device_tokens', ['token' => self::PUSH_TOKEN]);
        $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'token' => 'ExponentPushToken[otherDevice]']);
        $this->assertTokenRejected($token);
    }

    public function test_logout_never_removes_another_accounts_push_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DeviceToken::query()->create(['user_id' => $other->id, 'token' => self::PUSH_TOKEN, 'platform' => 'ios']);

        $this->withToken($user->createToken('auth-token')->plainTextToken)
            ->postJson('/api/v1/auth/logout', ['push_token' => self::PUSH_TOKEN])
            ->assertOk();

        $this->assertDatabaseHas('device_tokens', ['user_id' => $other->id, 'token' => self::PUSH_TOKEN]);
    }

    public function test_logout_needs_a_valid_token_and_a_sane_push_token(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();

        $token = User::factory()->create()->createToken('auth-token')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/logout', ['push_token' => str_repeat('x', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['push_token']);
        $this->assertTokenAccepted($token);
    }

    // --- Idle tokens ----------------------------------------------------------------------------

    public function test_token_unused_for_longer_than_the_idle_limit_stops_working(): void
    {
        $token = User::factory()->create()->createToken('auth-token')->plainTextToken;

        $this->travel(91)->days();

        $this->assertTokenRejected($token);
    }

    public function test_token_in_regular_use_keeps_working_past_the_idle_limit(): void
    {
        $token = User::factory()->create()->createToken('auth-token')->plainTextToken;

        for ($month = 1; $month <= 4; $month++) {
            $this->travel(60)->days();
            $this->assertTokenAccepted($token);
        }
    }

    public function test_idle_limit_can_be_turned_off(): void
    {
        config(['sanctum.idle_expiration' => null]);
        $token = User::factory()->create()->createToken('auth-token')->plainTextToken;

        $this->travel(400)->days();

        $this->assertTokenAccepted($token);
    }

    public function test_prune_command_deletes_only_idle_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('lama');
        $usedToken = $user->createToken('dipakai');

        $this->travel(80)->days();
        $usedToken->accessToken->forceFill(['last_used_at' => now()])->save();
        $user->createToken('baru');
        $this->travel(15)->days();

        $this->artisan('sanctum:prune-idle')
            ->expectsOutput('Deleted 1 idle API token(s).')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['dipakai', 'baru'], PersonalAccessToken::query()->pluck('name')->all());
    }

    // --- Helpers --------------------------------------------------------------------------------

    private function login(string $email, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * Guards remember the user between requests in one test, so each check starts fresh.
     */
    private function assertTokenAccepted(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    private function assertTokenRejected(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
