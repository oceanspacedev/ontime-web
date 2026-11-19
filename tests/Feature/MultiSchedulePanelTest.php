<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Pages\JadwalMingguan;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\ShiftScheduleChange;
use App\Models\User;
use App\Services\ShiftAssignmentService;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * D1 in the web panel: the week grid shows one chip per schedule and adds a second one, "Atur Jadwal" can
 * add instead of replace, and the schedule list edits one row at a time, all through the same writer.
 *
 * Tuesday 17 November 2026; Luuca 08:00-15:00 and JChicken 16:00-22:00 is the two-outlet day.
 */
class MultiSchedulePanelTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-17';

    private Company $company;

    private CompanyLocation $jchickenOutlet;

    private CompanyLocation $luucaOutlet;

    private Shift $day;

    private Shift $evening;

    private Shift $afternoon;

    private User $coordinator;

    private Freelancer $gina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::DAY.' 06:00'));
        Role::findOrCreate('Koordinator', 'web');

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $luuca = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->jchickenOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->luucaOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $luuca->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        $this->day = Shift::create(['company_id' => $this->company->id, 'name' => 'Luuca Pagi', 'code' => 'LP', 'start_time' => '08:00', 'end_time' => '15:00']);
        $this->evening = Shift::create(['company_id' => $this->company->id, 'name' => 'JChicken Sore', 'code' => 'JS', 'start_time' => '16:00', 'end_time' => '22:00']);
        $this->afternoon = Shift::create(['company_id' => $this->company->id, 'name' => 'Siang', 'code' => 'SG', 'start_time' => '14:00', 'end_time' => '22:00']);

        $this->coordinator = User::factory()->create(['name' => 'Loocita']);
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$jchicken->id, $luuca->id]);
        $this->actingAs($this->coordinator);

        $this->gina = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $jchicken->id, 'freelancer_number' => 'FL-07', 'full_name' => 'Gina', 'status' => 'active']);
    }

    public function test_the_week_grid_shows_a_chip_per_schedule_and_an_add_button(): void
    {
        $this->schedule($this->day, $this->luucaOutlet);
        $this->schedule($this->evening, $this->jchickenOutlet, sequence: 2);

        Livewire::test(JadwalMingguan::class)
            ->assertSee('LP')
            ->assertSee('JS')
            ->assertSee('Luuca Ciledug')
            ->assertSee('JChicken Ciledug')
            ->assertSee('+ Jadwal');
    }

    public function test_a_second_schedule_is_added_from_the_cell(): void
    {
        $first = $this->schedule($this->day, $this->luucaOutlet);

        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['shift_id' => $this->evening->id, 'company_location_id' => $this->jchickenOutlet->id], $this->cellArguments(['add' => true]))
            ->assertHasNoActionErrors()
            ->assertNotified('Jadwal tambahan disimpan');

        $this->assertSame(
            [[1, $this->day->id, $this->luucaOutlet->id], [2, $this->evening->id, $this->jchickenOutlet->id]],
            $this->rows(),
        );
        $this->assertSame($this->day->id, $first->fresh()->shift_id);
        $this->assertSame('panel', ShiftScheduleChange::query()->latest('id')->value('source'));
    }

    public function test_adding_an_overlapping_schedule_from_the_cell_is_refused(): void
    {
        $this->schedule($this->day, $this->luucaOutlet);

        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['shift_id' => $this->afternoon->id, 'company_location_id' => $this->jchickenOutlet->id], $this->cellArguments(['add' => true]))
            ->assertNotified(FilamentNotification::make()->title('Jadwal tidak disimpan')->body(ShiftAssignmentService::OVERLAP_MESSAGE)->danger());

        $this->assertCount(1, $this->rows());
    }

    public function test_a_second_schedule_is_added_from_atur_jadwal(): void
    {
        $this->schedule($this->day, $this->luucaOutlet);
        $other = Freelancer::create(['company_id' => $this->company->id, 'division_id' => $this->gina->division_id, 'freelancer_number' => 'FL-08', 'full_name' => 'Hana', 'status' => 'active']);
        ShiftSchedule::create(['freelancer_id' => $other->id, 'date' => self::DAY, 'shift_id' => $this->afternoon->id, 'company_location_id' => $this->luucaOutlet->id]);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('assignSchedule', data: [
                'company_id' => $this->company->id,
                'workers' => ['freelancer:'.$this->gina->id, 'freelancer:'.$other->id],
                'date_from' => self::DAY,
                'date_until' => self::DAY,
                'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'mode' => 'shift',
                'shift_id' => $this->evening->id,
                'company_location_id' => $this->jchickenOutlet->id,
                'placement' => ManageShiftSchedules::PLACEMENT_ADD,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified(FilamentNotification::make()->title('Jadwal shift disimpan')->body('2 pekerja, 1 hari. Baru: 1, diperbarui: 0, dilewati: 0. 1 hari tidak ditambah karena hari itu libur, sudah punya 3 jadwal, shift yang sama sudah ada, atau jamnya bertabrakan.')->warning());

        $this->assertSame([[1, $this->day->id, $this->luucaOutlet->id], [2, $this->evening->id, $this->jchickenOutlet->id]], $this->rows());
        $this->assertSame([$this->afternoon->id], ShiftSchedule::where('freelancer_id', $other->id)->pluck('shift_id')->all(), 'The overlapping addition leaves the day as it was.');
    }

    public function test_replacing_from_atur_jadwal_still_changes_the_days_schedule(): void
    {
        $this->schedule($this->day, $this->luucaOutlet);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('assignSchedule', data: [
                'company_id' => $this->company->id,
                'workers' => ['freelancer:'.$this->gina->id],
                'date_from' => self::DAY,
                'date_until' => self::DAY,
                'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'mode' => 'shift',
                'shift_id' => $this->evening->id,
                'company_location_id' => $this->jchickenOutlet->id,
                'placement' => ManageShiftSchedules::PLACEMENT_REPLACE,
                'overwrite' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertSame([[1, $this->evening->id, $this->jchickenOutlet->id]], $this->rows());
    }

    public function test_editing_or_clearing_one_chip_leaves_the_other(): void
    {
        $first = $this->schedule($this->day, $this->luucaOutlet);
        $second = $this->schedule($this->evening, $this->jchickenOutlet, sequence: 2);
        $late = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam', 'code' => 'ML', 'start_time' => '17:00', 'end_time' => '23:00']);

        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['mode' => 'shift', 'shift_id' => $late->id, 'company_location_id' => $this->jchickenOutlet->id], $this->cellArguments(['schedule_id' => $second->id]))
            ->assertHasNoActionErrors()
            ->assertNotified('Jadwal disimpan');

        $this->assertSame([[1, $this->day->id, $this->luucaOutlet->id], [2, $late->id, $this->jchickenOutlet->id]], $this->rows());

        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['mode' => 'clear'], $this->cellArguments(['schedule_id' => $first->id]))
            ->assertNotified('Jadwal dihapus');

        $this->assertSame([[2, $late->id, $this->jchickenOutlet->id]], $this->rows(), 'The second schedule keeps its number.');
    }

    public function test_a_locked_chip_cannot_be_edited(): void
    {
        $locked = $this->schedule($this->day, $this->luucaOutlet);
        $open = $this->schedule($this->evening, $this->jchickenOutlet, sequence: 2);
        Attendance::create([
            'freelancer_id' => $this->gina->id,
            'date' => self::DAY,
            'schedule_sequence' => 1,
            'segment' => 1,
            'shift_schedule_id' => $locked->id,
            'shift_id' => $this->day->id,
            'company_location_id' => $this->luucaOutlet->id,
            'check_in' => self::DAY.' 07:55:00',
            'status' => 'on_time',
            'late_minutes' => 0,
        ]);

        Livewire::test(JadwalMingguan::class)
            ->assertSeeHtml('data-locked-schedule="'.$locked->id.'"')
            ->assertDontSeeHtml('data-locked-schedule="'.$open->id.'"')
            ->assertActionDisabled(TestAction::make('editCell')->arguments($this->cellArguments(['schedule_id' => $locked->id])))
            ->assertActionEnabled(TestAction::make('editCell')->arguments($this->cellArguments(['schedule_id' => $open->id])))
            ->callAction('editCell', ['mode' => 'clear'], $this->cellArguments(['schedule_id' => $locked->id]));

        $this->assertCount(2, $this->rows(), 'A disabled action does not run.');
    }

    public function test_editing_a_past_chip_requires_a_reason(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 09:00'));
        $first = $this->schedule($this->day, $this->luucaOutlet);
        $second = $this->schedule($this->evening, $this->jchickenOutlet, sequence: 2);

        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['mode' => 'clear'], $this->cellArguments(['schedule_id' => $second->id]))
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertCount(2, $this->rows());

        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['mode' => 'clear', 'reason' => 'Tidak jadi ke JChicken'], $this->cellArguments(['schedule_id' => $second->id]))
            ->assertHasNoActionErrors();

        $this->assertSame([[1, $this->day->id, $this->luucaOutlet->id]], $this->rows());
        $this->assertSame($first->id, ShiftSchedule::where('freelancer_id', $this->gina->id)->value('id'));
        $this->assertSame('Tidak jadi ke JChicken', ShiftScheduleChange::query()->latest('id')->value('reason'));
    }

    public function test_the_schedule_list_shows_the_number_and_edits_with_the_day_guards(): void
    {
        $this->schedule($this->day, $this->luucaOutlet);
        $second = $this->schedule($this->evening, $this->jchickenOutlet, sequence: 2);

        Livewire::test(ManageShiftSchedules::class)
            ->assertTableColumnExists('sequence')
            ->assertCanSeeTableRecords([$second])
            ->callAction(TestAction::make('edit')->table($second), ['is_day_off' => false, 'shift_id' => $this->afternoon->id, 'company_location_id' => $this->jchickenOutlet->id])
            ->assertNotified(FilamentNotification::make()->title('Jadwal tidak disimpan')->body(ShiftAssignmentService::OVERLAP_MESSAGE)->danger());

        $this->assertSame($this->evening->id, $second->fresh()->shift_id);

        Livewire::test(ManageShiftSchedules::class)
            ->callAction(TestAction::make('edit')->table($second), ['is_day_off' => true])
            ->assertNotified(FilamentNotification::make()->title('Jadwal tidak disimpan')->body(ShiftAssignmentService::DAY_OFF_WITH_OTHERS_MESSAGE)->danger());

        $this->assertFalse($second->fresh()->is_day_off);
    }

    /**
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    private function cellArguments(array $more = []): array
    {
        return $more + ['type' => 'freelancer', 'id' => $this->gina->id, 'company_id' => $this->company->id, 'name' => $this->gina->full_name, 'date' => self::DAY];
    }

    private function schedule(Shift $shift, CompanyLocation $outlet, int $sequence = 1): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $this->gina->id, 'date' => self::DAY, 'sequence' => $sequence, 'shift_id' => $shift->id, 'company_location_id' => $outlet->id]);
    }

    /**
     * @return list<array{0: int, 1: int|null, 2: int|null}>
     */
    private function rows(): array
    {
        return ShiftSchedule::where('freelancer_id', $this->gina->id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (ShiftSchedule $row): array => [(int) $row->sequence, $row->shift_id, $row->company_location_id])
            ->all();
    }
}
