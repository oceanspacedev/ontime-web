<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource;
use App\Models\Company;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CreateUserActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_create_user_action_uses_employee_email_phone_and_selected_role(): void
    {
        config(['services.whatsapp_gateway.token' => 'test-token']);
        Http::fake([
            'waghub.mekayastudio.com/*' => Http::response(['status' => true], 200),
            '*' => Http::response(['status' => true], 200),
        ]);

        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin.test@ontime.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('Superadmin');

        $company = Company::create(['name' => 'PT Test', 'code' => 'TEST', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Divisi IT', 'code' => 'IT', 'is_active' => true]);
        $jobLevel = JobLevel::create(['name' => 'Staff', 'level_order' => 1]);
        $jobTitle = JobTitle::create(['name' => 'Developer']);

        $employee = Employee::create([
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP.TEST.001',
            'full_name' => 'Budi Santoso',
            'email' => 'budi.santoso@company.com',
            'phone' => '081234567890',
            'join_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        Livewire::test(EmployeeResource\Pages\ListEmployees::class)
            ->callTableAction('createUser', $employee, [
                'email' => 'budi.santoso@company.com',
                'role' => 'Approver',
                'password' => 'secret123',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $employee->refresh();
        $this->assertNotNull($employee->user_id);

        $user = User::find($employee->user_id);
        $this->assertEquals('budi.santoso@company.com', $user->email);
        $this->assertEquals('6281234567890', $user->phone);
        $this->assertTrue($user->hasRole('Approver'));

        Http::assertSent(function ($request) {
            $body = json_encode($request->data());

            return (str_contains($request->url(), 'waghub.mekayastudio.com') || str_contains($request->url(), 'messages'))
                && str_contains($body, 'Login via WhatsApp OTP')
                && str_contains($body, 'budi.santoso@company.com');
        });
    }

    public function test_delete_user_action_removes_user_account_and_unlinks_employee(): void
    {
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin.delete@ontime.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('Superadmin');

        $company = Company::create(['name' => 'PT Test 2', 'code' => 'TEST2', 'is_active' => true]);
        $division = Division::create(['company_id' => $company->id, 'name' => 'Divisi IT', 'code' => 'IT2', 'is_active' => true]);
        $jobLevel = JobLevel::create(['name' => 'Staff 2', 'level_order' => 2]);
        $jobTitle = JobTitle::create(['name' => 'Developer 2']);

        $userToDelete = User::create([
            'name' => 'Joko Susilo',
            'email' => 'joko@company.com',
            'password' => bcrypt('password'),
        ]);

        $employee = Employee::create([
            'company_id' => $company->id,
            'division_id' => $division->id,
            'job_level_id' => $jobLevel->id,
            'job_title_id' => $jobTitle->id,
            'nip' => 'EMP.TEST.002',
            'full_name' => 'Joko Susilo',
            'email' => 'joko@company.com',
            'user_id' => $userToDelete->id,
            'join_date' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($admin);

        Livewire::test(EmployeeResource\Pages\ListEmployees::class)
            ->callTableAction('deleteUser', $employee)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $employee->refresh();
        $this->assertNull($employee->user_id);
        $this->assertNull(User::find($userToDelete->id));
    }
}
