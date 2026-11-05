<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'ExponentPushToken[abcDEF123-_xyz]';

    public function test_registering_a_device_requires_authentication(): void
    {
        $this->postJson('/api/v1/devices', ['token' => self::TOKEN, 'platform' => 'ios'])->assertUnauthorized();
        $this->deleteJson('/api/v1/devices', ['token' => self::TOKEN])->assertUnauthorized();
    }

    public function test_user_registers_a_device_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/devices', ['token' => self::TOKEN, 'platform' => 'android'])
            ->assertCreated()
            ->assertJsonPath('message', 'Perangkat terdaftar untuk notifikasi.')
            ->assertJsonPath('device.token', self::TOKEN)
            ->assertJsonPath('device.platform', 'android');

        $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'token' => self::TOKEN, 'platform' => 'android']);
        $this->assertNotNull(DeviceToken::query()->first()->last_seen_at);
    }

    public function test_registering_the_same_token_again_refreshes_it_instead_of_duplicating(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->travelTo(now()->subDay());
        $this->postJson('/api/v1/devices', ['token' => self::TOKEN, 'platform' => 'ios'])->assertCreated();
        $firstSeen = DeviceToken::query()->sole()->last_seen_at;

        $this->travelBack();
        $this->postJson('/api/v1/devices', ['token' => self::TOKEN, 'platform' => 'ios'])->assertOk();

        $this->assertSame(1, DeviceToken::query()->count());
        $this->assertTrue(DeviceToken::query()->sole()->last_seen_at->greaterThan($firstSeen));
    }

    public function test_a_token_moves_to_the_account_that_signs_in_on_the_device(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson('/api/v1/devices', ['token' => self::TOKEN, 'platform' => 'ios'])->assertCreated();

        Sanctum::actingAs($second);
        $this->postJson('/api/v1/devices', ['token' => self::TOKEN, 'platform' => 'ios'])->assertOk();

        $this->assertSame(1, DeviceToken::query()->count());
        $this->assertDatabaseHas('device_tokens', ['user_id' => $second->id, 'token' => self::TOKEN]);
        $this->assertDatabaseMissing('device_tokens', ['user_id' => $first->id]);
    }

    public function test_registration_validates_the_token_and_platform(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/devices', ['token' => 'bukan-token', 'platform' => 'ios'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        $this->postJson('/api/v1/devices', ['token' => self::TOKEN, 'platform' => 'windows'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);

        $this->postJson('/api/v1/devices', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token', 'platform']);

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_user_removes_their_own_device_token_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => self::TOKEN, 'platform' => 'ios']);
        DeviceToken::query()->create(['user_id' => $other->id, 'token' => 'ExponentPushToken[other]', 'platform' => 'ios']);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/devices', ['token' => 'ExponentPushToken[other]'])
            ->assertOk()
            ->assertJsonPath('message', 'Perangkat dihapus dari notifikasi.');
        $this->assertDatabaseHas('device_tokens', ['token' => 'ExponentPushToken[other]']);

        $this->deleteJson('/api/v1/devices', ['token' => self::TOKEN])->assertOk();
        $this->assertDatabaseMissing('device_tokens', ['token' => self::TOKEN]);

        $this->deleteJson('/api/v1/devices', [])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    }

    public function test_device_tokens_disappear_with_their_user(): void
    {
        $user = User::factory()->create();
        DeviceToken::query()->create(['user_id' => $user->id, 'token' => self::TOKEN, 'platform' => 'ios']);

        $user->delete();

        $this->assertSame(0, DeviceToken::query()->count());
    }
}
