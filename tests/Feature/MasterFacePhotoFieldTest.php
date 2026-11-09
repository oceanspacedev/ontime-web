<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\FreelanceResource\Pages\EditFreelancer;
use App\Filament\Resources\InternResource\Pages\EditIntern;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\SelfieUploadToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The master face photo on the admin Employee, Intern and Freelance forms is saved as a storage path,
 * never as the upload token the capture component keeps in the form state.
 */
class MasterFacePhotoFieldTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 'public']);
        Storage::fake('public');

        foreach (['Superadmin', 'Employee'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');
        $this->actingAs($this->superadmin);

        $this->company = Company::create(['name' => 'PT Wajah', 'code' => 'WJH', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function workerForms(): array
    {
        return [
            'karyawan' => ['employee'],
            'magang' => ['intern'],
            'freelance' => ['freelancer'],
        ];
    }

    #[DataProvider('workerForms')]
    public function test_upload_token_of_the_admin_is_saved_as_the_storage_path(string $kind): void
    {
        [$record, $page] = $this->makeWorker($kind);

        $token = $this->postJson(route('upload.selfie'), ['photo' => UploadedFile::fake()->image('master.jpg', 200, 200)])
            ->assertOk()
            ->json('token');

        Livewire::test($page, ['record' => $record->getKey()])
            ->fillForm(['master_face_photo' => $token])
            ->call('save')
            ->assertHasNoFormErrors();

        $storedPath = $record->fresh()->master_face_photo;
        $this->assertNotSame($token, $storedPath);
        $this->assertLessThanOrEqual(255, strlen((string) $storedPath));
        Storage::disk('public')->assertExists($storedPath);
    }

    #[DataProvider('workerForms')]
    public function test_unchanged_stored_path_is_kept(string $kind): void
    {
        [$record, $page] = $this->makeWorker($kind, 'master-faces/stored.jpg');

        Livewire::test($page, ['record' => $record->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('master-faces/stored.jpg', $record->fresh()->master_face_photo);
    }

    #[DataProvider('workerForms')]
    public function test_raw_path_or_foreign_token_is_refused(string $kind): void
    {
        [$record, $page] = $this->makeWorker($kind, 'master-faces/stored.jpg');
        $otherUser = User::factory()->create();

        foreach (['attendance/photos/someone-else.jpg', SelfieUploadToken::issue('attendance/photos/other.jpg', $otherUser)] as $submitted) {
            Livewire::test($page, ['record' => $record->getKey()])
                ->fillForm(['master_face_photo' => $submitted])
                ->call('save')
                ->assertHasFormErrors(['master_face_photo']);
        }

        $this->assertSame('master-faces/stored.jpg', $record->fresh()->master_face_photo);
    }

    public function test_saving_again_after_the_token_expired_keeps_the_photo(): void
    {
        [$record, $page] = $this->makeWorker('employee');
        $token = SelfieUploadToken::issue('master-faces/new.jpg', $this->superadmin);

        $form = Livewire::test($page, ['record' => $record->getKey()])
            ->fillForm(['master_face_photo' => $token])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('master-faces/new.jpg', $record->fresh()->master_face_photo);

        $this->travel(SelfieUploadToken::VALID_FOR_MINUTES + 5)->minutes();

        $form->call('save')->assertHasNoFormErrors();

        $this->assertSame('master-faces/new.jpg', $record->fresh()->master_face_photo);
    }

    public function test_expired_token_for_a_new_photo_is_refused(): void
    {
        [$record, $page] = $this->makeWorker('employee', 'master-faces/stored.jpg');
        $token = SelfieUploadToken::issue('master-faces/new.jpg', $this->superadmin);

        $this->travel(SelfieUploadToken::VALID_FOR_MINUTES + 1)->minutes();

        Livewire::test($page, ['record' => $record->getKey()])
            ->fillForm(['master_face_photo' => $token])
            ->call('save')
            ->assertHasFormErrors(['master_face_photo']);

        $this->assertSame('master-faces/stored.jpg', $record->fresh()->master_face_photo);
    }

    public function test_removed_photo_is_saved_as_empty(): void
    {
        [$record, $page] = $this->makeWorker('employee', 'master-faces/stored.jpg');

        Livewire::test($page, ['record' => $record->getKey()])
            ->fillForm(['master_face_photo' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($record->fresh()->master_face_photo);
    }

    /**
     * @return array{Model, class-string}
     */
    private function makeWorker(string $kind, ?string $masterFacePhoto = null): array
    {
        $common = [
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'status' => 'active',
            'master_face_photo' => $masterFacePhoto,
        ];

        return match ($kind) {
            'employee' => [Employee::create([
                ...$common,
                'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
                'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
                'nip' => 'WJH-001',
                'full_name' => 'Karyawan Wajah',
                'join_date' => '2025-01-06',
            ]), EditEmployee::class],
            'intern' => [Intern::create([
                ...$common,
                'nis' => 'MG-WJH',
                'full_name' => 'Magang Wajah',
                'institution' => 'SMK',
                'start_date' => '2026-07-20',
                'end_date' => '2027-01-20',
            ]), EditIntern::class],
            'freelancer' => [Freelancer::create([
                ...$common,
                'freelancer_number' => 'FL-WJH',
                'full_name' => 'Freelance Wajah',
                'start_date' => '2026-07-20',
                'end_date' => '2027-01-20',
            ]), EditFreelancer::class],
        };
    }
}
