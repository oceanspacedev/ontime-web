<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * One login per role for UAT on a local or staging copy of the data (see UAT.md), placed in
 * CV Top Selular > DISTRIBUTION SALES > REALME:
 *
 * | Email                        | Role       | Akses Data             | Data pekerja           |
 * |------------------------------|------------|------------------------|------------------------|
 * | uat.superadmin@ontime.test   | Superadmin | Semua                  | -                      |
 * | uat.admin.top@ontime.test    | Admin      | Badan Usaha: TOP       | -                      |
 * | uat.admin.realme@ontime.test | Admin      | Divisi: REALME         | -                      |
 * | uat.bod@ontime.test          | BOD        | Badan Usaha: TOP       | -                      |
 * | uat.approver@ontime.test     | Approver   | Divisi: DEPO BANDUNG   | Karyawan DEPO BANDUNG  |
 * | uat.karyawan@ontime.test     | Employee   | Pribadi                | Karyawan DEPO BANDUNG  |
 * | uat.karyawan2@ontime.test    | Employee   | Pribadi                | Karyawan DEPO CIREBON  |
 * | uat.magang@ontime.test       | Intern     | Pribadi                | Magang DEPO BANDUNG    |
 * | uat.freelancer@ontime.test   | Freelancer | Pribadi                | Freelancer DEPO BANDUNG|
 *
 * Every account uses the password "password". Run with: php artisan db:seed --class=UatAccountSeeder
 */
class UatAccountSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('UatAccountSeeder creates accounts with a known password and must not run in production.');
        }

        $company = Company::query()->where('code', 'TOP')->firstOrFail();
        $sales = $this->division($company, 'DISTRIBUTION SALES', null);
        $realme = $this->division($company, 'REALME', $sales);
        $bandung = $this->division($company, 'DEPO BANDUNG', $realme);
        $cirebon = $this->division($company, 'DEPO CIREBON', $realme);

        $this->account('uat.superadmin@ontime.test', 'UAT Superadmin', 'Superadmin');
        $this->account('uat.admin.top@ontime.test', 'UAT Admin TOP', 'Admin')
            ->grantDataAccess(DataScope::Companies, companyIds: [$company->id]);
        $this->account('uat.admin.realme@ontime.test', 'UAT Admin REALME', 'Admin')
            ->grantDataAccess(DataScope::Divisions, divisionIds: [$realme->id]);
        $this->account('uat.bod@ontime.test', 'UAT BOD TOP', 'BOD')
            ->grantDataAccess(DataScope::Companies, companyIds: [$company->id]);

        $approver = $this->account('uat.approver@ontime.test', 'UAT Approver Bandung', 'Approver')
            ->grantDataAccess(DataScope::Divisions, divisionIds: [$bandung->id]);
        $this->linkEmployee($approver, $bandung);

        $karyawan = $this->account('uat.karyawan@ontime.test', 'UAT Karyawan Bandung', 'Employee');
        $mentor = $this->linkEmployee($karyawan, $bandung);

        $this->linkEmployee($this->account('uat.karyawan2@ontime.test', 'UAT Karyawan Cirebon', 'Employee'), $cirebon);

        $magang = $this->account('uat.magang@ontime.test', 'UAT Magang Bandung', 'Intern');
        Intern::query()->updateOrCreate(['nis' => 'UAT-MAGANG-01'], [
            'user_id' => $magang->id,
            'company_id' => $company->id,
            'division_id' => $bandung->id,
            'mentor_id' => $mentor->id,
            'full_name' => 'UAT Magang Bandung',
            'institution' => 'SMK Uji Coba',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);

        $freelancer = $this->account('uat.freelancer@ontime.test', 'UAT Freelancer Bandung', 'Freelancer');
        Freelancer::query()->updateOrCreate(['freelancer_number' => 'UAT-FL-01'], [
            'user_id' => $freelancer->id,
            'company_id' => $company->id,
            'division_id' => $bandung->id,
            'supervisor_id' => $mentor->id,
            'full_name' => 'UAT Freelancer Bandung',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'status' => 'active',
        ]);
    }

    private function account(string $email, string $name, string $role): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
        ]);
        $user->syncRoles([$role]);

        return $user;
    }

    /**
     * Link the account to an active employee of the division, keeping the one linked on a previous run.
     */
    private function linkEmployee(User $user, Division $division): Employee
    {
        $employee = Employee::query()->where('user_id', $user->id)->first()
            ?? Employee::query()
                ->where('division_id', $division->id)
                ->where('status', 'active')
                ->whereNull('user_id')
                ->orderBy('id')
                ->firstOrFail();

        $employee->update(['user_id' => $user->id]);

        return $employee;
    }

    private function division(Company $company, string $name, ?Division $parent): Division
    {
        return Division::query()
            ->where('company_id', $company->id)
            ->where('name', $name)
            ->when($parent, fn ($query) => $query->where('parent_id', $parent->id), fn ($query) => $query->whereNull('parent_id'))
            ->orderBy('id')
            ->firstOrFail();
    }
}
