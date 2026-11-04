<?php

namespace Tests\Feature;

use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShieldRoleSetupTest extends TestCase
{
    use RefreshDatabase;

    private Role $superadminRole;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Superadmin', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::create(['name' => $role]);
        }

        $this->superadminRole = Role::findByName('Superadmin');

        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);
    }

    public function test_shield_treats_the_application_superadmin_role_as_super_admin(): void
    {
        $this->assertSame('Superadmin', Utils::getSuperAdminName());
        $this->assertFalse(Utils::isPanelUserRoleEnabled());
        $this->assertFalse(config('filament-shield.policies.generate'));
    }

    public function test_system_roles_cannot_be_deleted(): void
    {
        $approver = Role::findByName('Approver');

        Livewire::test(ListRoles::class)
            ->callAction(TestAction::make('delete')->table($approver))
            ->assertNotified('Data tidak dapat dihapus');

        $this->assertModelExists($approver);
        $this->assertFalse($this->superadminRole->delete());
    }

    public function test_system_roles_cannot_be_renamed(): void
    {
        $this->expectException(ValidationException::class);

        Role::findByName('BOD')->update(['name' => 'Direksi']);
    }

    public function test_custom_roles_can_be_renamed_and_deleted(): void
    {
        $custom = Role::create(['name' => 'Auditor']);

        $custom->update(['name' => 'Auditor Internal']);
        $this->assertSame('Auditor Internal', $custom->fresh()->name);

        $this->assertTrue($custom->delete());
        $this->assertModelMissing($custom);
    }

    public function test_role_edit_page_renders_and_keeps_system_role_names(): void
    {
        Livewire::test(EditRole::class, ['record' => $this->superadminRole->id])
            ->assertSuccessful()
            ->fillForm(['name' => 'Admin Utama'])
            ->call('save')
            ->assertHasFormErrors(['name']);

        $this->assertSame('Superadmin', $this->superadminRole->fresh()->name);
    }

    public function test_migration_moves_shield_super_admin_holders_to_superadmin(): void
    {
        $legacyRole = Role::create(['name' => 'super_admin']);
        $legacyHolder = User::factory()->create();
        $legacyHolder->assignRole($legacyRole);

        $migration = require database_path('migrations/2026_11_04_102110_merge_shield_super_admin_role_into_superadmin.php');
        $migration->up();

        $this->assertTrue($legacyHolder->fresh()->hasRole('Superadmin'));
        $this->assertDatabaseMissing('roles', ['name' => 'super_admin']);
    }
}
