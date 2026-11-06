<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * A franchise company to try the Koordinator role on a local copy: five brands with one outlet each
 * in Ciledug, their shifts, freelancers, and today's and tomorrow's schedules.
 *
 * | Email                 | Role                    | Akses Data                                    |
 * |-----------------------|-------------------------|-----------------------------------------------|
 * | loocita@ontime.test   | Koordinator + Employee  | Divisi: JCHICKEN, LUUCA, WIZMIE, KOPIULON     |
 * | karyawan@ontime.test  | Employee (Kasir JChicken) | Pribadi                                     |
 * | freelance@ontime.test | Freelancer (Bayu, JChicken) | Pribadi                                   |
 * | magang@ontime.test    | Intern (Luuca)          | Pribadi                                       |
 *
 * MOMOYO is left out of her access on purpose, to show the brand limits. The password is "password".
 * Run with: php artisan db:seed --class=CoordinatorDemoSeeder
 */
class CoordinatorDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /** Brand => [code, name, start, end] shifts, from the outlets' own schedule sheets. */
    private const SHIFTS = [
        'JCHICKEN' => [
            ['P', 'Pagi', '08:00', '17:00'],
            ['PO', 'Pagi Opening', '08:00', '13:30'],
            ['M10', 'Midle 10', '10:00', '19:00'],
            ['M12', 'Midle 12', '12:00', '21:00'],
            ['S', 'Siang', '13:30', '22:30'],
            ['SC', 'Sore Closing', '17:00', '22:30'],
            ['SC19', 'Sore Closing 19', '19:00', '22:30'],
        ],
        'MOMOYO' => [
            ['P', 'Pagi', '08:00', '17:00'],
            ['M10', 'Midle 10', '10:00', '19:00'],
            ['M11', 'Midle 11', '11:00', '20:00'],
            ['S', 'Siang', '13:00', '22:00'],
            ['SC', 'Sore Closing', '17:00', '22:00'],
            ['HK', 'Housekeeping', '10:00', '19:00'],
        ],
        'LUUCA' => [
            ['P08', 'Pagi 08', '08:00', '17:00'],
            ['P', 'Pagi 09', '09:00', '18:00'],
            ['PO', 'Pagi Opening', '09:00', '13:30'],
            ['S', 'Siang 13', '13:30', '22:30'],
            ['S14', 'Siang 14', '14:30', '22:30'],
            ['SC', 'Sore Closing', '18:00', '22:30'],
        ],
        'WIZMIE' => [
            ['P', 'Pagi', '08:00', '17:00'],
            ['S', 'Siang', '13:00', '22:00'],
        ],
        'KOPIULON' => [
            ['P', 'Pagi', '07:00', '16:00'],
            ['S', 'Siang', '14:00', '23:00'],
        ],
    ];

    private const OUTLET_NAMES = [
        'JCHICKEN' => 'JChicken Ciledug',
        'MOMOYO' => 'Momoyo Ciledug',
        'LUUCA' => 'Luuca Ciledug',
        'WIZMIE' => 'Wizmie Ciledug',
        'KOPIULON' => 'Kopiulon Ciledug',
    ];

    /** Brand => freelancer names. */
    private const FREELANCERS = [
        'JCHICKEN' => ['Bayu Adillah', 'David Saputra', 'Tyo Saputra', 'Roma Wulandari'],
        'LUUCA' => ['Dafid Nugroho', 'Fajri Khoirul Anam', 'Wikrio Rahmawanto'],
        'WIZMIE' => ['Sari Wulandari'],
        'KOPIULON' => ['Andi Pratama'],
        'MOMOYO' => ['Egi Febyan Dika', 'Camelia Putri'],
    ];

    private function workerAccount(string $email, string $name, string $role): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make(self::PASSWORD), 'is_active' => true],
        );
        $user->syncRoles([$role]);

        return $user;
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('CoordinatorDemoSeeder creates an account with a known password and must not run in production.');
        }

        Role::findOrCreate('Koordinator', 'web');
        foreach (['Employee', 'Freelancer', 'Intern'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $company = Company::query()->firstOrCreate(
            ['code' => 'FCD'],
            ['name' => 'PT Franchise Ciledug (Demo)', 'is_active' => true],
        );
        app(ApprovalFlowService::class)->createDefaultFlows($company);
        $company->policy()->update(['require_gps' => false, 'require_photo' => false, 'require_face_recognition' => false, 'require_liveness' => false]);

        $brands = [];
        $outlets = [];
        $shifts = [];

        foreach (array_keys(self::SHIFTS) as $index => $brandName) {
            $brand = Division::query()->firstOrCreate(['company_id' => $company->id, 'name' => $brandName, 'parent_id' => null], ['is_active' => true]);
            $brands[$brandName] = $brand;

            $outlets[$brandName] = CompanyLocation::query()->updateOrCreate(
                ['company_id' => $company->id, 'division_id' => $brand->id],
                ['name' => self::OUTLET_NAMES[$brandName], 'latitude' => -6.2285 + $index * 0.0002, 'longitude' => 106.7120, 'radius_meters' => 150, 'is_active' => true],
            );

            foreach (self::SHIFTS[$brandName] as [$code, $name, $start, $end]) {
                $shifts[$brandName][$code] = Shift::query()->updateOrCreate(
                    ['company_id' => $company->id, 'division_id' => $brand->id, 'name' => $name],
                    ['code' => $code, 'start_time' => $start, 'end_time' => $end, 'is_active' => true],
                );
            }
        }

        $loocita = User::query()->updateOrCreate(
            ['email' => 'loocita@ontime.test'],
            ['name' => 'Aisyiyah Dian Locita', 'password' => Hash::make(self::PASSWORD), 'is_active' => true],
        );
        $loocita->syncRoles(['Koordinator', 'Employee']);
        $loocita->grantDataAccess(DataScope::Divisions, divisionIds: [$brands['JCHICKEN']->id, $brands['LUUCA']->id, $brands['WIZMIE']->id, $brands['KOPIULON']->id]);

        Employee::query()->updateOrCreate(
            ['nip' => 'FCD-SM-001'],
            [
                'user_id' => $loocita->id,
                'company_id' => $company->id,
                'division_id' => $brands['JCHICKEN']->id,
                'job_level_id' => JobLevel::query()->firstOrCreate(['name' => 'MANAGER'], ['level_order' => 3])->id,
                'job_title_id' => JobTitle::query()->firstOrCreate(['name' => 'STORE MANAGER', 'division_id' => $brands['JCHICKEN']->id])->id,
                'full_name' => 'Aisyiyah Dian Locita',
                'status' => 'active',
            ],
        );

        $freelancers = [];

        foreach (self::FREELANCERS as $brandName => $names) {
            foreach ($names as $name) {
                $freelancers[$name] = Freelancer::query()->firstOrCreate(
                    ['company_id' => $company->id, 'full_name' => $name],
                    ['division_id' => $brands[$brandName]->id, 'phone' => '0812'.random_int(10000000, 99999999), 'start_date' => today()->subMonths(2), 'status' => 'active'],
                );
            }
        }

        // Daily users: one employee, one freelancer and one intern with their own logins.
        $karyawan = $this->workerAccount('karyawan@ontime.test', 'Rizky Kasir JChicken', 'Employee');
        $karyawanProfile = Employee::query()->updateOrCreate(
            ['nip' => 'FCD-KS-001'],
            [
                'user_id' => $karyawan->id,
                'company_id' => $company->id,
                'division_id' => $brands['JCHICKEN']->id,
                'job_level_id' => JobLevel::query()->firstOrCreate(['name' => 'STAFF'], ['level_order' => 1])->id,
                'job_title_id' => JobTitle::query()->firstOrCreate(['name' => 'KASIR', 'division_id' => $brands['JCHICKEN']->id])->id,
                'full_name' => 'Rizky Kasir JChicken',
                'status' => 'active',
            ],
        );

        $freelancers['Bayu Adillah']->update(['user_id' => $this->workerAccount('freelance@ontime.test', 'Bayu Adillah', 'Freelancer')->id]);

        $magang = $this->workerAccount('magang@ontime.test', 'Nadia Magang Luuca', 'Intern');
        $magangProfile = Intern::query()->updateOrCreate(
            ['nis' => 'FCD-MG-001'],
            ['user_id' => $magang->id, 'company_id' => $company->id, 'division_id' => $brands['LUUCA']->id, 'full_name' => 'Nadia Magang Luuca', 'institution' => 'SMK Pariwisata', 'status' => 'active', 'start_date' => today()->subMonth()],
        );

        // One freelancer who already left, to show the "Sudah keluar" list.
        Freelancer::query()->firstOrCreate(
            ['company_id' => $company->id, 'full_name' => 'Neza Ayu Ramadhani'],
            ['division_id' => $brands['JCHICKEN']->id, 'start_date' => today()->subMonths(4), 'end_date' => today()->subWeek(), 'status' => 'completed'],
        );

        $today = today();
        $tomorrow = today()->addDay();

        $plan = [
            // [name, brand of shift, shift code, outlet brand, section, day]
            ['Bayu Adillah', 'JCHICKEN', 'P', 'JCHICKEN', 'Dining', $today],
            ['David Saputra', 'JCHICKEN', 'M10', 'JCHICKEN', 'Kasir', $today],
            ['Tyo Saputra', 'JCHICKEN', 'S', 'JCHICKEN', 'Cook', $today],
            ['Dafid Nugroho', 'LUUCA', 'P', 'LUUCA', 'Kasir', $today],
            ['Fajri Khoirul Anam', 'LUUCA', 'S', 'LUUCA', 'Bar', $today],
            ['Roma Wulandari', 'LUUCA', 'PO', 'LUUCA', 'Kasir', $today],
            ['Sari Wulandari', 'WIZMIE', 'P', 'WIZMIE', null, $today],
            ['Andi Pratama', 'KOPIULON', 'P', 'KOPIULON', 'Bar', $today],
            ['Egi Febyan Dika', 'MOMOYO', 'P', 'MOMOYO', 'Kasir', $today],
            ['Bayu Adillah', 'JCHICKEN', 'SC', 'JCHICKEN', 'Dining', $tomorrow],
            ['David Saputra', 'LUUCA', 'S', 'LUUCA', 'Kasir', $tomorrow],
            ['Dafid Nugroho', 'LUUCA', 'P', 'LUUCA', 'Kasir', $tomorrow],
        ];

        foreach ($plan as [$name, $shiftBrand, $code, $outletBrand, $section, $day]) {
            ShiftSchedule::query()->updateOrCreate(
                ['freelancer_id' => $freelancers[$name]->id, 'date' => $day->toDateString()],
                ['shift_id' => $shifts[$shiftBrand][$code]->id, 'company_location_id' => $outlets[$outletBrand]->id, 'section' => $section, 'is_day_off' => false, 'created_by' => $loocita->id],
            );
        }

        ShiftSchedule::query()->updateOrCreate(
            ['employee_id' => $karyawanProfile->id, 'date' => $today->toDateString()],
            ['shift_id' => $shifts['JCHICKEN']['M10']->id, 'company_location_id' => $outlets['JCHICKEN']->id, 'section' => 'Kasir', 'is_day_off' => false, 'created_by' => $loocita->id],
        );
        ShiftSchedule::query()->updateOrCreate(
            ['intern_id' => $magangProfile->id, 'date' => $today->toDateString()],
            ['shift_id' => $shifts['LUUCA']['P']->id, 'company_location_id' => $outlets['LUUCA']->id, 'section' => 'Bar', 'is_day_off' => false, 'created_by' => $loocita->id],
        );

        ShiftSchedule::query()->updateOrCreate(
            ['freelancer_id' => $freelancers['Wikrio Rahmawanto']->id, 'date' => $today->toDateString()],
            ['shift_id' => null, 'company_location_id' => null, 'section' => null, 'is_day_off' => true, 'created_by' => $loocita->id],
        );

        // Today's attendance: some on time, one late, the rest still to come.
        foreach ([
            ['Dafid Nugroho', '09:22', AttendanceStatus::Late, 22],
            ['Sari Wulandari', '07:58', AttendanceStatus::OnTime, 0],
            ['Egi Febyan Dika', '07:50', AttendanceStatus::OnTime, 0],
        ] as [$name, $time, $status, $late]) {
            Attendance::query()->updateOrCreate(
                ['freelancer_id' => $freelancers[$name]->id, 'date' => $today->toDateString()],
                ['check_in' => $today->copy()->setTimeFromTimeString($time), 'status' => $status, 'late_minutes' => $late],
            );
        }
    }
}
