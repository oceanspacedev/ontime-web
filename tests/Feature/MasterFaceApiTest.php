<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Services\Face\FaceDetection;
use App\Services\Face\FaceMatch;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MasterFaceApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->seed(RoleSeeder::class);

        $company = Company::create([
            'name' => 'PT Master Face Test',
            'code' => 'PTMFT',
            'is_active' => true,
        ]);

        $division = Division::create([
            'company_id' => $company->id,
            'name' => 'Divisi IT',
            'code' => 'DIVIT',
            'is_active' => true,
        ]);

        $jobLevel = JobLevel::firstOrCreate(['name' => 'Staff', 'level_order' => 1]);
        $jobTitle = JobTitle::firstOrCreate(['name' => 'Programmer']);

        $this->user = User::factory()->create([
            'email' => 'masterface@example.com',
        ]);
        $this->user->assignRole('Employee');

        $this->employee = Employee::create([
            'user_id' => $this->user->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'full_name' => 'Master Face User',
            'nip' => 'MF-1001',
            'join_date' => now()->toDateString(),
            'status' => 'permanent',
        ]);
    }

    public function test_user_can_upload_master_face_photo_via_api(): void
    {
        Storage::fake(config('filesystems.default'));
        Sanctum::actingAs($this->user);

        $file = UploadedFile::fake()->image('master_face.jpg', 300, 300);

        $response = $this->postJson('/api/v1/attendance/master-face', [
            'photo' => $file,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'master_face_photo',
                    'master_face_verified_at',
                ],
            ]);

        $this->employee->refresh();
        $this->assertNotNull($this->employee->master_face_photo);
        $this->assertNotNull($this->employee->master_face_verified_at);
        Storage::disk(config('filesystems.default'))->assertExists($this->employee->master_face_photo);
    }

    public function test_master_face_upload_validation_fails_without_photo(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/attendance/master-face', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);
    }

    public function test_profile_photo_upload_says_foto_profil(): void
    {
        Storage::fake(config('filesystems.default'));
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('profil.jpg', 300, 300)])
            ->assertOk()
            ->assertJsonPath('message', 'Foto profil berhasil diperbarui.');
    }

    public function test_profile_photo_without_a_face_is_refused_when_the_face_service_can_tell(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->useFaceVerifier(faces: 0);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('kartun.jpg', 300, 300)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo'])
            ->assertJsonPath('errors.photo.0', fn (string $message): bool => str_contains($message, 'Wajah tidak terlihat'));

        $this->assertNull($this->employee->refresh()->master_face_photo);
        $this->assertSame([], Storage::disk(config('filesystems.default'))->allFiles());
    }

    public function test_profile_photo_with_more_than_one_face_is_refused(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->useFaceVerifier(faces: 2);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('rame.jpg', 300, 300)])
            ->assertStatus(422)
            ->assertJsonPath('errors.photo.0', fn (string $message): bool => str_contains($message, 'lebih dari satu wajah'));

        $this->assertNull($this->employee->refresh()->master_face_photo);
    }

    public function test_profile_photo_with_one_face_is_saved_when_the_face_service_checks_it(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->useFaceVerifier(faces: 1);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('wajah.jpg', 300, 300)])
            ->assertOk();

        $this->assertNotNull($this->employee->refresh()->master_face_photo);
    }

    public function test_profile_photo_is_saved_when_the_face_service_is_down(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->useFaceVerifier(faces: null);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('wajah.jpg', 300, 300)])
            ->assertOk();

        $this->assertNotNull($this->employee->refresh()->master_face_photo);
    }

    public function test_profile_photo_is_not_face_checked_by_the_basic_driver(): void
    {
        Storage::fake(config('filesystems.default'));
        $verifier = $this->useFaceVerifier(faces: 0, accurate: false);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('apa-saja.jpg', 300, 300)])
            ->assertOk();

        $this->assertSame(0, $verifier->detectCalls);
        $this->assertNotNull($this->employee->refresh()->master_face_photo);
    }

    public function test_account_without_a_worker_profile_cannot_set_a_profile_photo(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('wajah.jpg', 300, 300)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Profil pengguna tidak ditemukan.');
    }

    public function test_deleting_the_profile_photo_clears_it(): void
    {
        $this->employee->update(['master_face_photo' => 'master-faces/lama.jpg', 'master_face_verified_at' => now()]);
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/attendance/master-face')
            ->assertOk()
            ->assertJsonPath('message', 'Foto profil berhasil dihapus.');

        $this->employee->refresh();
        $this->assertNull($this->employee->master_face_photo);
        $this->assertNull($this->employee->master_face_verified_at);

        $this->deleteJson('/api/v1/attendance/master-face')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Foto profil belum pernah dipasang.');
    }

    public function test_intern_can_set_and_remove_a_profile_photo(): void
    {
        Storage::fake(config('filesystems.default'));
        $internUser = User::factory()->create();
        $internUser->assignRole('Intern');
        $intern = Intern::create([
            'user_id' => $internUser->id,
            'company_id' => $this->employee->company_id,
            'division_id' => $this->employee->division_id,
            'mentor_id' => $this->employee->id,
            'nis' => 'MG-PP1',
            'full_name' => 'Magang Foto',
            'institution' => 'SMK',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        Sanctum::actingAs($internUser);

        $this->postJson('/api/v1/attendance/master-face', ['photo' => UploadedFile::fake()->image('magang.jpg', 300, 300)])
            ->assertOk();
        $this->assertNotNull($intern->refresh()->master_face_photo);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.intern.master_face_photo', fn (?string $url): bool => filled($url));

        $this->deleteJson('/api/v1/attendance/master-face')->assertOk();
        $this->assertNull($intern->refresh()->master_face_photo);
    }

    /**
     * Bind a face driver that reports the given number of faces, or is unreachable when $faces is null.
     */
    private function useFaceVerifier(?int $faces, bool $accurate = true): FaceVerifier
    {
        $verifier = new class($faces, $accurate) implements FaceVerifier
        {
            public int $detectCalls = 0;

            public function __construct(private ?int $faces, private bool $accurate) {}

            public function name(): string
            {
                return 'fake';
            }

            public function supportsPose(): bool
            {
                return false;
            }

            public function isAccurate(): bool
            {
                return $this->accurate;
            }

            public function verify(string $selfiePath, string $masterPath, int $thresholdPercent): FaceMatch
            {
                return new FaceMatch(true, 100, 'cocok');
            }

            public function detect(string $imagePath): FaceDetection
            {
                $this->detectCalls++;

                if ($this->faces === null) {
                    throw new FaceServiceUnavailable('Layanan wajah tidak bisa dihubungi.');
                }

                return new FaceDetection($this->faces);
            }
        };

        $this->app->instance(FaceVerifier::class, $verifier);

        return $verifier;
    }
}
