<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Files saved while FILESYSTEM_DISK was local or public are copied onto the new default disk.
 */
class CopyStoredFilesToDefaultDiskTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.default' => 's3']);
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('s3');

        $company = Company::create(['name' => 'CV Top', 'code' => 'TOP', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Sales', 'is_active' => true]);

        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => JobLevel::create(['name' => 'Staff', 'level_order' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'Staff'])->id,
            'nip' => 'NIP-1',
            'full_name' => 'Pekerja Satu',
            'status' => 'active',
            'master_face_photo' => 'master-faces/wajah.jpg',
        ]);

        Storage::disk('local')->put('master-faces/wajah.jpg', 'wajah');
        Storage::disk('public')->put('attendance/photos/masuk.jpg', 'masuk');
        Storage::disk('local')->put('attendance/liveness/frame-1.jpg', 'frame');
        Storage::disk('local')->put('attendance/photos/keluar.jpg', 'lama');
        Storage::disk('s3')->put('attendance/photos/keluar.jpg', 'sudah di s3');
        Storage::disk('local')->put('leave-requests/attachments/surat.pdf', '%PDF-1.4');

        Attendance::create([
            'employee_id' => $this->employee->id,
            'date' => today(),
            'status' => 'on_time',
            'check_in_photo' => 'attendance/photos/masuk.jpg',
            'check_out_photo' => 'attendance/photos/keluar.jpg',
            'liveness_frames' => ['attendance/liveness/frame-1.jpg', 'attendance/liveness/hilang.jpg'],
        ]);

        LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'leave_type' => 'sick',
            'start_date' => today(),
            'end_date' => today(),
            'days_count' => 1,
            'reason' => 'Sakit',
            'status' => 'pending',
            'attachment' => 'leave-requests/attachments/surat.pdf',
        ]);
    }

    public function test_it_copies_referenced_files_from_local_and_public_to_the_default_disk(): void
    {
        $this->artisan('storage:copy-to-default-disk')
            ->expectsOutputToContain('Disalin ke s3: 4 file. Sudah ada: 1. Tidak ditemukan: 1.')
            ->assertSuccessful();

        Storage::disk('s3')->assertExists([
            'master-faces/wajah.jpg',
            'attendance/photos/masuk.jpg',
            'attendance/liveness/frame-1.jpg',
            'leave-requests/attachments/surat.pdf',
        ]);
        $this->assertSame('wajah', Storage::disk('s3')->get('master-faces/wajah.jpg'));
        $this->assertSame('masuk', Storage::disk('s3')->get('attendance/photos/masuk.jpg'));
        $this->assertSame('sudah di s3', Storage::disk('s3')->get('attendance/photos/keluar.jpg'));

        Storage::disk('local')->assertExists('master-faces/wajah.jpg');
        Storage::disk('public')->assertExists('attendance/photos/masuk.jpg');
    }

    public function test_running_it_again_copies_nothing(): void
    {
        $this->artisan('storage:copy-to-default-disk')->assertSuccessful();

        $this->artisan('storage:copy-to-default-disk')
            ->expectsOutputToContain('Disalin ke s3: 0 file. Sudah ada: 5. Tidak ditemukan: 1.')
            ->assertSuccessful();
    }

    public function test_dry_run_only_reports(): void
    {
        $this->artisan('storage:copy-to-default-disk', ['--dry-run' => true])
            ->expectsOutputToContain('Akan disalin ke s3: 4 file.')
            ->assertSuccessful();

        Storage::disk('s3')->assertMissing(['master-faces/wajah.jpg', 'attendance/photos/masuk.jpg']);
    }

    public function test_files_on_the_public_disk_move_to_a_local_default_disk(): void
    {
        config(['filesystems.default' => 'local']);

        $this->artisan('storage:copy-to-default-disk')
            ->expectsOutputToContain('Disalin ke local: 1 file.')
            ->assertSuccessful();

        Storage::disk('local')->assertExists('attendance/photos/masuk.jpg');
    }
}
