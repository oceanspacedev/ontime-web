<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Company;
use App\Models\User;
use App\Support\ObservabilityAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ObservabilityAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Admin', 'BOD'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_guests_cannot_access_horizon_or_log_viewer(): void
    {
        $this->assertFalse(ObservabilityAccess::allowed(null));
        $this->assertSame('horizon', config('horizon.path'));
        $this->assertSame('log-viewer', config('log-viewer.route_path'));
        $this->assertTrue(Gate::has('viewLogViewer'));
        $this->assertTrue(Gate::has('viewHorizon'));

        $this->get('/horizon')->assertForbidden();
        $this->get('/log-viewer')->assertForbidden();
    }

    public function test_superadmin_and_admin_with_full_data_access_can_open_both(): void
    {
        $superadmin = $this->makeUser('Superadmin');
        $globalAdmin = $this->makeUser('Admin', DataScope::All);

        foreach ([$superadmin, $globalAdmin] as $user) {
            $this->assertTrue(ObservabilityAccess::allowed($user));
            $this->actingAs($user)->get('/horizon')->assertOk();
            $this->actingAs($user)->get('/log-viewer')->assertOk();
        }
    }

    public function test_company_admin_and_non_managers_are_refused(): void
    {
        $companyAdmin = $this->makeUser('Admin', DataScope::Companies, [Company::create(['name' => 'CV Top', 'code' => 'TOP', 'is_active' => true])->id]);
        $bodWithFullAccess = $this->makeUser('BOD', DataScope::All);

        foreach ([$companyAdmin, $bodWithFullAccess] as $user) {
            $this->assertFalse(ObservabilityAccess::allowed($user));
            $this->actingAs($user)->get('/horizon')->assertForbidden();
            $this->actingAs($user)->get('/log-viewer')->assertForbidden();
        }
    }

    public function test_admin_menu_links_are_only_shown_to_allowed_users(): void
    {
        $items = collect(ObservabilityAccess::filamentNavigationItems());

        $this->actingAs($this->makeUser('Superadmin'));
        $this->assertSame(['Horizon', 'Log Viewer'], $items->filter->isVisible()->map->getLabel()->values()->all());

        $this->actingAs($this->makeUser('BOD', DataScope::All));
        $this->assertSame([], $items->filter->isVisible()->values()->all());
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function makeUser(string $role, DataScope $scope = DataScope::Own, array $companyIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->grantDataAccess($scope, companyIds: $companyIds);
    }
}
