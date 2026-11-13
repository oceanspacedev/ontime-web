<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Clean & Modular DatabaseSeeder for Production & Demo environment.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('DatabaseSeeder membuat akun demo dengan password umum dan tidak boleh dijalankan di production. Buat admin pertama secara manual atau pakai UatAccountSeeder.');
        }

        // 1. Setup Roles
        $superadminRole = Role::firstOrCreate(['name' => 'Superadmin']);
        $bodRole = Role::firstOrCreate(['name' => 'BOD']);
        $approverRole = Role::firstOrCreate(['name' => 'Approver']);
        $employeeRole = Role::firstOrCreate(['name' => 'Employee']);
        $internRole = Role::firstOrCreate(['name' => 'Intern']);
        $freelancerRole = Role::firstOrCreate(['name' => 'Freelancer']);

        // 2. Global Superadmin Account
        $superAdminUser = User::firstOrCreate(
            ['email' => 'admin@ontime.oceanspace.co.id'],
            [
                'name' => 'Super Admin OnTime',
                'password' => Hash::make('password'),
            ]
        );
        $superAdminUser->assignRole($superadminRole);

        // 3. Seed Production Data (Companies, Divisions, Job Titles, Employees)
        $this->call(ProductionSeeder::class);

        // 4. Seed Company Geofence Locations (CS, TOP, MSI, SMI, RISM)
        $this->call(CompanyLocationSeeder::class);

        // 5. Setup Approval Flows & Policies for all Companies
        foreach (Company::with('policy')->get() as $comp) {
            if ($comp->policy) {
                $comp->policy->update([
                    'late_tolerance_minutes' => 15,
                    'require_photo' => true,
                    'require_gps' => true,
                    'geofence_radius_meters' => 100,
                    'annual_leave_quota' => 12,
                    'work_start_time' => '08:00:00',
                    'work_end_time' => '17:00:00',
                ]);
            }

            app(ApprovalFlowService::class)->createDefaultFlows($comp);
        }

        // 6. Setup Specific Role Test Accounts for Demo
        $approverUser = User::firstOrCreate(
            ['email' => 'approver@ontime.oceanspace.co.id'],
            [
                'name' => 'Approver Demo',
                'password' => Hash::make('password'),
            ]
        );
        $approverUser->assignRole($approverRole);
        $approverUser->grantDataAccess(DataScope::All);

        $bodUser = User::firstOrCreate(
            ['email' => 'bod@ontime.oceanspace.co.id'],
            [
                'name' => 'BOD Demo',
                'password' => Hash::make('password'),
            ]
        );
        $bodUser->assignRole($bodRole);
        $bodUser->grantDataAccess(DataScope::All);

        // Assign Employee Role to All Generated Users from ProductionSeeder
        foreach (User::all() as $usr) {
            if ($usr->roles()->count() === 0) {
                $usr->assignRole($employeeRole);
            }
        }

        // 7. Seed SMK Wikrama Bogor IT Interns & Freelancer Accounts for Demo
        $company = Company::where('code', 'CS')->first() ?? Company::first();
        $itDivision = Division::where('company_id', $company?->id)
            ->where('name', 'IT SUPPORT')
            ->first() ?? Division::where('company_id', $company?->id)->where('name', 'LIKE', '%IT%')->first();
        $sampleMentor = Employee::where('company_id', $company?->id)->first() ?? Employee::first();

        if ($company && $itDivision) {
            $internsData = [
                ['nis' => '2026.07.20.01', 'name' => 'MUHAMMAD AFRIZA', 'username' => 'muhammadafriza', 'phone' => '6285187382679'],
                ['nis' => '2026.07.20.02', 'name' => 'DINAR MUHAMAD RIVAI', 'username' => 'dinarmuhamadrivai', 'phone' => '6285810117452'],
                ['nis' => '2026.07.20.03', 'name' => 'AHTAR MAULANA GURNING', 'username' => 'ahtarmaulanagurning', 'phone' => '6287864322667'],
                ['nis' => '2026.07.20.04', 'name' => 'ANDHIKA RAFI', 'username' => 'andhikarafi', 'phone' => '6285890045994'],
                ['nis' => '2026.07.20.05', 'name' => 'MUHAMAAD AZKA ALFARISYI', 'username' => 'muhamaadazkaalfarisyi', 'phone' => '6289699405754'],
                ['nis' => '2026.07.20.06', 'name' => 'MUHAMAD NAUFAL BARLAMAN', 'username' => 'muhamadnaufalbarlaman', 'phone' => '6285863023306'],
            ];

            foreach ($internsData as $data) {
                $email = "{$data['username']}@ontime.oceanspace.co.id";
                $internUser = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $data['name'],
                        'phone' => $data['phone'],
                        'password' => Hash::make('password'),
                    ]
                );
                $internUser->update(['phone' => $data['phone']]);
                $internUser->syncRoles([$internRole]);

                Intern::updateOrCreate(
                    ['nis' => $data['nis']],
                    [
                        'user_id' => $internUser->id,
                        'company_id' => $company->id,
                        'division_id' => $itDivision->id,
                        'mentor_id' => $sampleMentor?->id,
                        'full_name' => $data['name'],
                        'institution' => 'SMK Wikrama Bogor',
                        'email' => $email,
                        'phone' => $data['phone'],
                        'start_date' => '2026-07-20',
                        'end_date' => '2026-10-20',
                        'status' => 'active',
                    ]
                );
            }

            // Sample Freelancer Setup
            $freelancerUser = User::firstOrCreate(
                ['email' => 'freelance1@ontime.oceanspace.co.id'],
                [
                    'name' => 'Siti Freelance',
                    'phone' => '6289876543210',
                    'password' => Hash::make('password'),
                ]
            );
            $freelancerUser->update(['phone' => '6289876543210']);
            $freelancerUser->syncRoles([$freelancerRole]);

            Freelancer::firstOrCreate(
                ['freelancer_number' => 'FL-2026-001'],
                [
                    'user_id' => $freelancerUser->id,
                    'company_id' => $company->id,
                    'division_id' => $itDivision->id,
                    'supervisor_id' => $sampleMentor?->id,
                    'full_name' => 'Siti Freelance',
                    'institution' => 'Freelance Professional',
                    'email' => 'freelance1@ontime.oceanspace.co.id',
                    'phone' => '6289876543210',
                    'start_date' => '2026-07-20',
                    'end_date' => '2026-11-20',
                    'status' => 'active',
                ]
            );
        }

        // 8. Seed Attendance Sample Data for Demo Charts & Tables
        $this->call(AttendanceSeeder::class);
    }
}
