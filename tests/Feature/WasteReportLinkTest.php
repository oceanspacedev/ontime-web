<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
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
