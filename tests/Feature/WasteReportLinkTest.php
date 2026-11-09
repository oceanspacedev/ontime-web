<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\WasteReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WasteReportLinkTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://cesa.completeselular.com/waste';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cesa.waste_url' => self::URL, 'services.cesa.waste_brands' => ['JCHICKEN', 'MOMOYO', 'LUUCA']]);

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
    }

    public function test_crew_below_an_fnb_brand_get_the_link(): void
    {
        $jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JChicken', 'is_active' => true]);
        $ciledug = Division::create(['company_id' => $this->company->id, 'parent_id' => $jchicken->id, 'name' => 'JCHICKEN CILEDUG', 'is_active' => true]);
        $crew = $this->freelancerIn($ciledug);

        $this->assertSame(self::URL, WasteReport::urlFor($crew), 'Brand names match without case, through parent divisions.');

        Sanctum::actingAs($crew);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.access.waste_url', self::URL);
    }

    public function test_employees_and_interns_of_a_brand_get_it_too(): void
    {
        $momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
        Role::findOrCreate('Employee', 'web');
        Role::findOrCreate('Intern', 'web');

        $employee = User::factory()->create();
        $employee->assignRole('Employee');
        Employee::create([
            'user_id' => $employee->id, 'company_id' => $this->company->id, 'division_id' => $momoyo->id,
            'job_level_id' => JobLevel::create(['name' => 'STAFF', 'level_order' => 1])->id,
            'job_title_id' => JobTitle::create(['name' => 'KASIR'])->id,
            'nip' => 'MMY-1', 'full_name' => 'Kasir Momoyo', 'status' => 'active',
        ]);

        $intern = User::factory()->create();
        $intern->assignRole('Intern');
        Intern::create(['user_id' => $intern->id, 'company_id' => $this->company->id, 'division_id' => $momoyo->id, 'nis' => 'MG-1', 'full_name' => 'Magang Momoyo', 'institution' => 'SMK', 'status' => 'active']);

        $this->assertSame(self::URL, WasteReport::urlFor($employee->fresh()));
        $this->assertSame(self::URL, WasteReport::urlFor($intern->fresh()));
    }

    public function test_crew_of_other_brands_and_unplaced_accounts_do_not(): void
    {
        $wizmie = Division::create(['company_id' => $this->company->id, 'name' => 'WIZMIE', 'is_active' => true]);

        $this->assertNull(WasteReport::urlFor($this->freelancerIn($wizmie)));
        $this->assertNull(WasteReport::urlFor(User::factory()->create()));

        Sanctum::actingAs($this->freelancerIn($wizmie));
        $this->getJson('/api/v1/auth/me')->assertJsonPath('user.access.waste_url', null);
    }

    public function test_coordinators_always_get_it_and_the_link_can_be_switched_off(): void
    {
        $wizmie = Division::create(['company_id' => $this->company->id, 'name' => 'WIZMIE', 'is_active' => true]);
        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$wizmie->id]);

        $this->assertSame(self::URL, WasteReport::urlFor($coordinator));

        config(['services.cesa.waste_url' => null]);
        $this->assertNull(WasteReport::urlFor($coordinator));
    }

    private function freelancerIn(Division $division): User
    {
        $user = User::factory()->create();
        $user->assignRole('Freelancer');
        Freelancer::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'division_id' => $division->id, 'full_name' => 'Crew '.$user->id, 'status' => 'active']);

        return $user->fresh();
    }
}
