<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\StorageUrl;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceListPhotoColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config(['filesystems.default' => 'public']);
        Storage::fake('public');
    }

    public function test_attendance_list_renders_the_check_in_and_check_out_photo_columns(): void
    {
        Storage::disk('public')->put('attendance-photos/masuk.jpg', 'masuk');
        Storage::disk('public')->put('attendance-photos/keluar.jpg', 'keluar');

        $attendance = Attendance::create([
            'employee_id' => $this->makeEmployee()->id,
            'date' => today(),
            'check_in' => now()->setTime(8, 0),
            'check_out' => now()->setTime(17, 0),
            'status' => AttendanceStatus::OnTime,
            'check_in_photo' => 'attendance-photos/masuk.jpg',
            'check_out_photo' => 'attendance-photos/keluar.jpg',
        ]);

        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);
        $this->freezeTime();

        Livewire::test(ListAttendances::class)
            ->toggleAllTableColumns()
            ->assertTableColumnVisible('check_in_photo')
            ->assertTableColumnVisible('check_out_photo')
            ->assertCanSeeTableRecords([$attendance])
            ->assertTableColumnStateSet('check_in_photo', StorageUrl::for('attendance-photos/masuk.jpg'), $attendance)
            ->assertTableColumnStateSet('check_out_photo', StorageUrl::for('attendance-photos/keluar.jpg'), $attendance)
            ->assertSee('Foto Masuk')
            ->assertSee('Foto Keluar');
    }

    private function makeEmployee(): Employee
    {
        $company = Company::create(['name' => 'PT Foto Presensi', 'code' => 'FOTO', 'is_active' => true]);

        $user = User::factory()->create();
        $user->assignRole('Employee');

        return Employee::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'division_id' => Division::firstOrCreate(
                ['company_id' => $company->id, 'name' => 'Operasional'],
                ['is_active' => true],
            )->id,
            'job_level_id' => JobLevel::firstOrCreate(['name' => 'Staff'], ['level_order' => 1])->id,
            'job_title_id' => JobTitle::firstOrCreate(['name' => 'Staff Operasional'])->id,
            'nip' => 'FOTO-'.$user->id,
            'full_name' => "Karyawan {$user->id}",
            'status' => 'active',
        ]);
    }
}
