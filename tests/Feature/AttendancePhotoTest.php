<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SelfieUploadToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendancePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    public function test_can_upload_selfie_photo_base64(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('upload.selfie'), [
            'photo_base64' => 'data:image/jpeg;base64,'.base64_encode($this->jpegBytes()),
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $this->assertNull($response->json('path'));

        $token = $response->json('token');
        $this->assertNotNull($token);

        $path = SelfieUploadToken::resolve($token, $user);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_base64_png_is_stored_with_the_detected_extension(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('upload.selfie'), [
            // The data URL claims JPEG, the bytes are PNG: the bytes win.
            'photo_base64' => 'data:image/jpeg;base64,'.base64_encode($this->pngBytes()),
        ])->assertOk();

        $this->assertStringEndsWith('.png', SelfieUploadToken::resolve($response->json('token'), $user));
    }

    public function test_text_file_named_jpg_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('upload.selfie'), [
                'photo' => UploadedFile::fake()->createWithContent('selfie.jpg', 'ini bukan gambar <?php echo 1; ?>'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Foto selfie harus berupa gambar jpeg, png, atau webp. Ambil ulang foto.')
            ->assertJsonMissingPath('token');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_photo_over_5120_kb_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('upload.selfie'), [
                'photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)->size(SelfieUploadToken::MAX_KILOBYTES + 1),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Foto selfie maksimal berukuran 5MB. Ambil ulang foto.')
            ->assertJsonMissingPath('token');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_valid_image_of_exactly_5120_kb_is_accepted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('upload.selfie'), [
                'photo' => UploadedFile::fake()->image('selfie.jpg', 80, 80)->size(SelfieUploadToken::MAX_KILOBYTES),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $path = SelfieUploadToken::resolve($response->json('token'), $user);
        Storage::disk('public')->assertExists($path);
    }

    public function test_multipart_extension_comes_from_the_bytes_not_the_client_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('upload.selfie'), [
                'photo' => UploadedFile::fake()->createWithContent('selfie.php', $this->pngBytes()),
            ])
            ->assertOk();

        $path = SelfieUploadToken::resolve($response->json('token'), $user);
        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_base64_text_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('upload.selfie'), [
                'photo_base64' => 'data:image/jpeg;base64,'.base64_encode('fake-image-content'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonMissingPath('token');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_base64_over_5120_kb_is_rejected(): void
    {
        $user = User::factory()->create();

        // A real JPEG header followed by padding, so only the size can reject it.
        $bytes = $this->jpegBytes().str_repeat("\0", SelfieUploadToken::MAX_KILOBYTES * 1024);

        $this->actingAs($user)
            ->postJson(route('upload.selfie'), [
                'photo_base64' => 'data:image/jpeg;base64,'.base64_encode($bytes),
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Foto selfie maksimal berukuran 5MB. Ambil ulang foto.')
            ->assertJsonMissingPath('token');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function jpegBytes(): string
    {
        return UploadedFile::fake()->image('selfie.jpg', 40, 40)->getContent();
    }

    private function pngBytes(): string
    {
        return UploadedFile::fake()->image('selfie.png', 40, 40)->getContent();
    }
}
