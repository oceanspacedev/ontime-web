<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Attendance\AttendanceRuleException;
use App\Support\SelfieUploadToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SelfieUploadTokenTest extends TestCase
{
    use RefreshDatabase;

    private const INVALID = 'Foto selfie tidak valid atau kedaluwarsa. Ambil ulang foto.';

    private function assertRejected(string $token, User $user): void
    {
        try {
            SelfieUploadToken::resolve($token, $user);
            $this->fail('AttendanceRuleException was not thrown.');
        } catch (AttendanceRuleException $exception) {
            $this->assertSame(self::INVALID, $exception->getMessage());
            $this->assertSame(422, $exception->status);
            $this->assertSame('photo', $exception->field);
        }
    }

    public function test_token_resolves_to_the_path_for_its_owner(): void
    {
        $user = User::factory()->create();

        $token = SelfieUploadToken::issue('attendance-photos/ATT_1.jpg', $user);

        $this->assertStringNotContainsString('attendance-photos', $token);
        $this->assertSame('attendance-photos/ATT_1.jpg', SelfieUploadToken::resolve($token, $user));
    }

    public function test_token_payload_matches_the_spec(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();

        $payload = json_decode(Crypt::decryptString(SelfieUploadToken::issue('attendance-photos/ATT_1.jpg', $user)), true);

        $this->assertSame([
            'path' => 'attendance-photos/ATT_1.jpg',
            'user_id' => $user->id,
            'expires' => now()->addMinutes(15)->timestamp,
        ], $payload);
    }

    public function test_token_of_another_user_is_rejected(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->assertRejected(SelfieUploadToken::issue('attendance-photos/ATT_1.jpg', $owner), $other);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = SelfieUploadToken::issue('attendance-photos/ATT_1.jpg', $user);

        $this->travel(14)->minutes();
        $this->assertSame('attendance-photos/ATT_1.jpg', SelfieUploadToken::resolve($token, $user));

        $this->travel(2)->minutes();
        $this->assertRejected($token, $user);
    }

    public function test_forged_or_malformed_tokens_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->assertRejected('master-faces/MASTER_FACE_1.jpg', $user);
        $this->assertRejected('', $user);
        $this->assertRejected(base64_encode(json_encode(['path' => 'master-faces/x.jpg', 'user_id' => $user->id])), $user);
        $this->assertRejected(Crypt::encryptString('not json'), $user);
        $this->assertRejected(Crypt::encryptString(json_encode(['path' => '', 'user_id' => $user->id, 'expires' => now()->addMinute()->timestamp])), $user);
        $this->assertRejected(Crypt::encryptString(json_encode(['path' => 'a.jpg', 'user_id' => $user->id])), $user);
        $this->assertRejected(Crypt::encryptString(json_encode(['path' => 'a.jpg', 'expires' => now()->addMinute()->timestamp])), $user);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = SelfieUploadToken::issue('attendance-photos/ATT_1.jpg', $user);

        $this->assertRejected(substr($token, 0, -4).'AAAA', $user);
    }
}
