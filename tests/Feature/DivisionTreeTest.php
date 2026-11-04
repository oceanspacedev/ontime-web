<?php

namespace Tests\Feature;

use App\Filament\Resources\DivisionResource\Pages\CreateDivision;
use App\Filament\Resources\DivisionResource\Pages\EditDivision;
use App\Filament\Resources\DivisionResource\Pages\ListDivisions;
use App\Models\Company;
use App\Models\Division;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DivisionTreeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $root;

    private Division $child;

    private Division $grandchild;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Superadmin']);
        $superadmin = User::factory()->create();
        $superadmin->assignRole('Superadmin');
        $this->actingAs($superadmin);

        $this->company = Company::create(['name' => 'PT Pohon', 'code' => 'PHN', 'is_active' => true]);
        $this->root = Division::create(['company_id' => $this->company->id, 'name' => 'Operasional', 'is_active' => true]);
        $this->child = Division::create(['company_id' => $this->company->id, 'parent_id' => $this->root->id, 'name' => 'Gudang', 'is_active' => true]);
        $this->grandchild = Division::create(['company_id' => $this->company->id, 'parent_id' => $this->child->id, 'name' => 'Gudang Barat', 'is_active' => true]);
        Division::create(['company_id' => $this->company->id, 'name' => 'Keuangan', 'is_active' => true]);
    }

    public function test_division_list_renders_nested_divisions(): void
    {
        Livewire::test(ListDivisions::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->root, $this->child, $this->grandchild])
            ->assertSee('— — Gudang Barat');
    }

    public function test_depth_is_counted_from_the_top_level(): void
    {
        $this->assertSame(0, $this->root->getDepth());
        $this->assertSame(2, $this->grandchild->getDepth());
    }

    public function test_create_page_lists_parent_options_as_a_tree(): void
    {
        Livewire::test(CreateDivision::class)
            ->fillForm(['company_id' => $this->company->id])
            ->assertSuccessful()
            ->fillForm(['company_id' => $this->company->id, 'parent_id' => $this->grandchild->id, 'name' => 'Gudang Barat 1', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('divisions', ['name' => 'Gudang Barat 1', 'parent_id' => $this->grandchild->id]);
    }

    public function test_edit_page_saves_a_division_with_a_parent(): void
    {
        Livewire::test(EditDivision::class, ['record' => $this->child->id])
            ->assertSuccessful()
            ->fillForm(['name' => 'Gudang Utama'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('divisions', ['id' => $this->child->id, 'name' => 'Gudang Utama', 'parent_id' => $this->root->id]);
    }

    public function test_a_division_cannot_be_moved_under_its_own_descendant(): void
    {
        Livewire::test(EditDivision::class, ['record' => $this->root->id])
            ->fillForm(['parent_id' => $this->grandchild->id])
            ->call('save')
            ->assertHasFormErrors(['parent_id']);

        $this->assertNull($this->root->fresh()->parent_id);
    }

    public function test_children_ids_include_every_level(): void
    {
        $this->assertEqualsCanonicalizing([$this->child->id, $this->grandchild->id], $this->root->getAllChildrenIds());
    }
}
