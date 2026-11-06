<?php

namespace Tests\Feature;

use App\Filament\Resources\FreelanceResource\Pages\CreateFreelancer;
use App\Filament\Resources\FreelanceResource\Pages\ListFreelancers;
use App\Models\Company;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\AttendanceCheckService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private Company $company;

    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        foreach (['Superadmin', 'Freelancer', 'Intern'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole('Superadmin');

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->division = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
    }

    public function test_new_freelancer_gets_a_number_and_an_account_in_one_step(): void
    {
        $this->actingAs($this->superadmin);

        Livewire::test(CreateFreelancer::class)
            ->fillForm([
                'full_name' => 'Bayu Adillah',
                'phone' => '081234567890',
                'company_id' => $this->company->id,
                'division_id' => $this->division->id,
                'status' => 'active',
                'create_account' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Akun Bayu Adillah dibuat');

        $freelancer = Freelancer::with('user')->sole();

        $this->assertSame('FL-PFC-0001', $freelancer->freelancer_number);
        $this->assertTrue($freelancer->user->hasRole('Freelancer'));
        $this->assertTrue($freelancer->user->is_active);
        $this->assertSame('fl-pfc-0001@freelance.local', $freelancer->user->email);
        $this->assertFalse(Hash::check('password123', $freelancer->user->password), 'Every account gets its own password.');
    }

    public function test_numbers_continue_per_company(): void
    {
        $this->makeFreelancer(['freelancer_number' => 'FL-PFC-0007']);

        $this->assertSame('FL-PFC-0008', $this->makeFreelancer()->freelancer_number);
        $this->assertSame('MANUAL-1', $this->makeFreelancer(['freelancer_number' => 'MANUAL-1'])->freelancer_number);
    }

    public function test_ending_a_contract_switches_the_account_off_and_drops_later_schedules(): void
    {
        $freelancer = $this->makeFreelancer(withAccount: true);
        $freelancer->user->createToken('app');
        $shift = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => today(), 'shift_id' => $shift->id]);
        ShiftSchedule::create(['freelancer_id' => $freelancer->id, 'date' => today()->addDay(), 'shift_id' => $shift->id]);

        $this->actingAs($this->superadmin);

        Livewire::test(ListFreelancers::class)
            ->callAction(TestAction::make('endContract')->table($freelancer))
            ->assertNotified('Kontrak diakhiri');

        $freelancer->refresh();
        $this->assertSame(['completed', today()->toDateString()], [$freelancer->status, $freelancer->end_date->toDateString()]);
        $this->assertFalse($freelancer->user->is_active);
        $this->assertSame(0, $freelancer->user->tokens()->count());
        $this->assertSame([today()->toDateString()], ShiftSchedule::where('freelancer_id', $freelancer->id)->get()->map(fn (ShiftSchedule $schedule): string => $schedule->date->toDateString())->all());

        $this->expectException(AttendanceRuleException::class);
        $this->expectExceptionMessage('Status kerja Anda tidak aktif');
        app(AttendanceCheckService::class)->checkIn($freelancer->user, new AttendanceCheckInput);
    }

    public function test_reactivating_brings_the_same_account_back(): void
    {
        $freelancer = $this->makeFreelancer(['status' => 'completed', 'end_date' => today()->subWeek()], withAccount: true);
        $freelancer->user->update(['is_active' => false]);
        $userId = $freelancer->user_id;

        $this->actingAs($this->superadmin);

        Livewire::test(ListFreelancers::class)
            ->assertActionHidden(TestAction::make('endContract')->table($freelancer))
            ->callAction(TestAction::make('reactivate')->table($freelancer))
            ->assertNotified('Freelancer aktif lagi');

        $freelancer->refresh();
        $this->assertSame(['active', null, $userId, true], [$freelancer->status, $freelancer->end_date, $freelancer->user_id, $freelancer->user->is_active]);
    }

    public function test_nightly_command_closes_contracts_past_their_end_date(): void
    {
        $expired = $this->makeFreelancer(['end_date' => today()->subDay()], withAccount: true);
        $running = $this->makeFreelancer(['end_date' => today()]);
        $intern = Intern::create(['company_id' => $this->company->id, 'division_id' => $this->division->id, 'nis' => 'MG-1', 'full_name' => 'Magang', 'institution' => 'SMK', 'status' => 'active', 'end_date' => today()->subDays(3)]);

        $this->artisan('workers:close-expired-contracts')->expectsOutput('2 kontrak diakhiri.')->assertSuccessful();

        $this->assertSame('completed', $expired->fresh()->status);
        $this->assertSame(today()->subDay()->toDateString(), $expired->fresh()->end_date->toDateString(), 'The original end date is kept.');
        $this->assertFalse($expired->user->fresh()->is_active);
        $this->assertSame('active', $running->fresh()->status);
        $this->assertSame('completed', $intern->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeFreelancer(array $attributes = [], bool $withAccount = false): Freelancer
    {
        $freelancer = Freelancer::create($attributes + [
            'company_id' => $this->company->id,
            'division_id' => $this->division->id,
            'full_name' => 'Freelancer '.uniqid(),
            'status' => 'active',
        ]);

        if ($withAccount) {
            $user = User::factory()->create(['is_active' => true]);
            $user->assignRole('Freelancer');
            $freelancer->update(['user_id' => $user->id]);
        }

        return $freelancer->fresh('user');
    }
}
