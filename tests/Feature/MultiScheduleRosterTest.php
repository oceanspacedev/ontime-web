<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Filament\Pages\JadwalMingguan;
use App\Filament\Resources\ShiftSchedules\Pages\ManageShiftSchedules;
use App\Filament\Widgets\OutletTodayWidget;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Notifications\AttendanceReminderNotification;
use App\Services\Attendance\CheckInPick;
use App\Services\AttendanceReminderService;
use App\Services\ScheduleCopyService;
use App\Services\SessionResolver;
use App\Services\ShiftAssignmentService;
use App\Services\ShiftSwapService;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * D1 on the roster side: a worker may have up to three schedules on one date (a second outlet, a second
 * shift). The team API adds and edits them one by one, the released app (no schedule id) cannot damage a
 * day with several, and every roster view, copy, swap and reminder treats each schedule on its own.
 *
 * Tuesday 17 November 2026; Luuca 08:00-15:00 and JChicken 16:00-22:00 is the two-outlet day.
 */
class MultiScheduleRosterTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-17';

    private Company $company;

    private Division $jchicken;

    private CompanyLocation $jchickenOutlet;

    private CompanyLocation $luucaOutlet;

    private Shift $day;

    private Shift $evening;

    private Shift $afternoon;

    private Shift $split;

    private Shift $night;

    private Shift $lateNight;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->travelTo(Carbon::parse(self::DAY.' 06:00'));

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $luuca = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->jchickenOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->luucaOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $luuca->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        $shift = fn (string $name, string $code, string $start, string $end, array $more = []): Shift => Shift::create($more + ['company_id' => $this->company->id, 'name' => $name, 'code' => $code, 'start_time' => $start, 'end_time' => $end]);
        $this->day = $shift('Luuca Pagi', 'LP', '08:00', '15:00');
        $this->evening = $shift('JChicken Sore', 'JS', '16:00', '22:00');
        $this->afternoon = $shift('Siang', 'SG', '14:00', '22:00');
        $this->split = $shift('Split', 'SP', '07:45', '13:00', ['second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $this->night = $shift('Malam', 'M', '22:00', '06:00');
        $this->lateNight = $shift('Larut', 'L', '23:00', '02:00');

        $this->coordinator = User::factory()->create(['name' => 'Loocita']);
        $this->coordinator->assignRole('Koordinator');
        $this->coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id, $luuca->id]);
        Sanctum::actingAs($this->coordinator);
    }

    // ── Writing several schedules through the API ─────────────────────────

    public function test_adding_a_schedule_creates_sequence_two_and_lists_the_day(): void
    {
        $gina = $this->freelancer('Gina');
        $first = $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $this->day->id, 'company_location_id' => $this->luucaOutlet->id])->assertOk();
        $this->assertSame(1, $first->json('data.sequence'));

        $second = $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $this->evening->id, 'company_location_id' => $this->jchickenOutlet->id, 'add' => true])
            ->assertOk()
            ->assertJsonPath('data.sequence', 2)
            ->assertJsonPath('data.shift_id', $this->evening->id)
            ->assertJsonCount(2, 'schedules');

        $this->assertSame([[1, $this->day->id], [2, $this->evening->id]], collect($second->json('schedules'))->map(fn (array $row): array => [$row['sequence'], $row['shift_id']])->all());
        $this->assertSame([false, false], collect($second->json('schedules'))->pluck('locked')->all());
        $this->assertSame(2, ShiftSchedule::where('freelancer_id', $gina->id)->count());
    }

    public function test_adding_is_refused_on_overlap_duplicate_a_fourth_row_and_a_day_off(): void
    {
        $gina = $this->freelancer('Gina');
        $this->schedule($gina, $this->day, $this->luucaOutlet);

        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $this->afternoon->id, 'add' => true])
            ->assertUnprocessable()->assertJsonPath('errors.shift_id.0', ShiftAssignmentService::OVERLAP_MESSAGE);
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $this->day->id, 'company_location_id' => $this->luucaOutlet->id, 'add' => true])
            ->assertUnprocessable()->assertJsonPath('errors.shift_id.0', ShiftAssignmentService::DUPLICATE_SCHEDULE_MESSAGE);

        // Overnight hours count in full: 23:00-02:00 lies inside 22:00-06:00 of the same date.
        $owl = $this->freelancer('Owl');
        $this->schedule($owl, $this->night, $this->jchickenOutlet);
        $this->putSchedule($owl, ['mode' => 'shift', 'shift_id' => $this->lateNight->id, 'add' => true])
            ->assertUnprocessable()->assertJsonPath('errors.shift_id.0', ShiftAssignmentService::OVERLAP_MESSAGE);

        // Three schedules at most.
        $early = Shift::create(['company_id' => $this->company->id, 'name' => 'Subuh', 'code' => 'SB', 'start_time' => '04:00', 'end_time' => '07:00']);
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $this->evening->id, 'add' => true])->assertOk()->assertJsonPath('data.sequence', 2);
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $early->id, 'add' => true])->assertOk()->assertJsonPath('data.sequence', 3);
        $tooMany = Shift::create(['company_id' => $this->company->id, 'name' => 'Larut Pagi', 'code' => 'LPG', 'start_time' => '23:00', 'end_time' => '23:30']);
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $tooMany->id, 'add' => true])
            ->assertUnprocessable()->assertJsonPath('errors.date.0', ShiftAssignmentService::MAX_SCHEDULES_MESSAGE);

        // A day off is the only row of its date.
        $resting = $this->freelancer('Rehat');
        ShiftSchedule::create(['freelancer_id' => $resting->id, 'date' => self::DAY, 'is_day_off' => true]);
        $this->putSchedule($resting, ['mode' => 'shift', 'shift_id' => $this->day->id, 'add' => true])
            ->assertUnprocessable()->assertJsonPath('errors.date.0', ShiftAssignmentService::ADD_ON_DAY_OFF_MESSAGE);

        $this->assertSame(3, ShiftSchedule::where('freelancer_id', $gina->id)->count());
        $this->assertSame(1, ShiftSchedule::where('freelancer_id', $owl->id)->count());
        $this->assertSame(1, ShiftSchedule::where('freelancer_id', $resting->id)->count());
    }

    public function test_two_identical_add_calls_create_one_row(): void
    {
        $gina = $this->freelancer('Gina');
        $this->schedule($gina, $this->day, $this->luucaOutlet);
        $add = ['mode' => 'shift', 'shift_id' => $this->evening->id, 'company_location_id' => $this->jchickenOutlet->id, 'add' => true];

        $this->putSchedule($gina, $add)->assertOk();
        $this->putSchedule($gina, $add)->assertUnprocessable();

        $this->assertSame(1, ShiftSchedule::where('freelancer_id', $gina->id)->where('shift_id', $this->evening->id)->count());
    }

    public function test_a_named_schedule_is_changed_or_cleared_exactly_and_keeps_its_number(): void
    {
        $gina = $this->freelancer('Gina');
        $first = $this->schedule($gina, $this->day, $this->luucaOutlet);
        $second = $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 2);
        $later = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam Pendek', 'code' => 'MP', 'start_time' => '17:00', 'end_time' => '22:00']);

        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $later->id, 'company_location_id' => $this->jchickenOutlet->id, 'schedule_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.sequence', 2);
        $this->assertSame([$this->day->id, $later->id], [$first->fresh()->shift_id, $second->fresh()->shift_id]);

        // A day off cannot sit next to another schedule.
        $this->putSchedule($gina, ['mode' => 'off', 'schedule_id' => $second->id])
            ->assertUnprocessable()->assertJsonPath('errors.mode.0', ShiftAssignmentService::DAY_OFF_WITH_OTHERS_MESSAGE);

        $this->putSchedule($gina, ['mode' => 'clear', 'schedule_id' => $first->id])->assertOk()->assertJsonCount(1, 'schedules');
        $this->assertNull($first->fresh());
        $this->assertSame(2, $second->fresh()->sequence, 'The number never changes.');

        // Another worker's schedule, or one of another date, is not this day's.
        $other = $this->schedule($this->freelancer('Lain'), $this->day, $this->luucaOutlet);
        $this->putSchedule($gina, ['mode' => 'clear', 'schedule_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('schedule_id');
        $this->assertNotNull($other->fresh());
    }

    public function test_the_released_app_cannot_damage_a_two_schedule_day(): void
    {
        $gina = $this->freelancer('Gina');
        $late = $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 1);
        $early = $this->schedule($gina, $this->day, $this->luucaOutlet, sequence: 2);
        $later = Shift::create(['company_id' => $this->company->id, 'name' => 'Pagi Awal', 'code' => 'PA', 'start_time' => '07:00', 'end_time' => '14:00']);

        // A shift edits the primary row: the earliest start (sequence 2 here), not the lowest number.
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $later->id, 'company_location_id' => $this->luucaOutlet->id])
            ->assertOk()
            ->assertJsonPath('data.id', $early->id);
        $this->assertSame([$this->evening->id, $later->id], [$late->fresh()->shift_id, $early->fresh()->shift_id]);

        foreach (['off', 'clear'] as $mode) {
            $this->putSchedule($gina, ['mode' => $mode])
                ->assertUnprocessable()
                ->assertJsonPath('errors.date.0', 'Hari ini punya lebih dari satu jadwal. Perbarui aplikasi atau ubah dari panel.');
        }

        $this->assertSame(2, ShiftSchedule::where('freelancer_id', $gina->id)->count(), 'Nothing was deleted.');

        // A single-schedule day works as it always did.
        $solo = $this->freelancer('Solo');
        $this->putSchedule($solo, ['mode' => 'shift', 'shift_id' => $this->day->id])->assertOk();
        $this->putSchedule($solo, ['mode' => 'off'])->assertOk()->assertJsonPath('data.is_day_off', true);
        $this->putSchedule($solo, ['mode' => 'clear'])->assertOk()->assertJsonPath('data', null);
        $this->assertSame(0, ShiftSchedule::where('freelancer_id', $solo->id)->count());
    }

    /**
     * The panel's week grid and "Atur Jadwal" do not name a schedule yet: on a day with several, a day off
     * or a clear is refused with a pointer to the schedule list, not "update the app or use the panel".
     */
    public function test_the_panel_refuses_a_whole_day_change_on_a_two_schedule_day_with_a_panel_message(): void
    {
        $gina = $this->freelancer('Gina');
        $this->schedule($gina, $this->day, $this->luucaOutlet);
        $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 2);
        $this->actingAs($this->coordinator);

        Livewire::test(JadwalMingguan::class)
            ->callAction('editCell', ['mode' => 'clear'], ['type' => 'freelancer', 'id' => $gina->id, 'company_id' => $this->company->id, 'name' => $gina->full_name, 'date' => self::DAY])
            ->assertNotified(FilamentNotification::make()->title('Jadwal tidak disimpan')->body(ShiftAssignmentService::PANEL_MULTI_SCHEDULE_MESSAGE)->danger());

        Livewire::test(ManageShiftSchedules::class)
            ->callAction('assignSchedule', data: [
                'company_id' => $this->company->id,
                'workers' => ['freelancer:'.$gina->id],
                'date_from' => self::DAY,
                'date_until' => self::DAY,
                'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'mode' => 'day_off',
                'overwrite' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified(FilamentNotification::make()->title('Jadwal tidak disimpan')->body('1 pekerja, 1 hari. Baru: 0, diperbarui: 0, dilewati: 0. 1 hari tidak diubah karena punya lebih dari satu jadwal; ubah atau hapus per jadwal dari daftar Jadwal Shift.')->danger());

        $this->assertSame(2, ShiftSchedule::where('freelancer_id', $gina->id)->count());
    }

    /**
     * Carried must-fix (h): a rejected check-in never was a worked session, so it does not lock the schedule.
     */
    public function test_a_rejected_check_in_does_not_lock_its_schedule(): void
    {
        $gina = $this->freelancer('Gina');
        $schedule = $this->schedule($gina, $this->day, $this->luucaOutlet);
        $attendance = $this->attend($gina, $schedule, '07:55', status: 'rejected');

        $this->assertFalse($schedule->isLocked());
        $this->putSchedule($gina, ['mode' => 'off', 'schedule_id' => $schedule->id])->assertOk()->assertJsonPath('data.is_day_off', true);

        $attendance->update(['status' => 'on_time']);
        $this->assertTrue($schedule->fresh()->isLocked());
        $this->putSchedule($gina, ['mode' => 'clear', 'schedule_id' => $schedule->id])->assertUnprocessable()->assertJsonPath('errors.date.0', ShiftSchedule::LOCKED_MESSAGE);
    }

    /**
     * Carried must-fix (g): a check-in without a schedule from the night before still running into the new
     * schedule's hours stands in the way, like one of the same date.
     */
    public function test_an_unlinked_overnight_check_in_of_the_day_before_blocks_an_overlapping_schedule(): void
    {
        $owl = $this->freelancer('Owl', ['shift_id' => $this->night->id]);
        Attendance::create(['freelancer_id' => $owl->id, 'date' => '2026-11-16', 'check_in' => '2026-11-16 22:05:00', 'status' => 'on_time', 'late_minutes' => 0, 'shift_id' => $this->night->id]);
        $dawn = Shift::create(['company_id' => $this->company->id, 'name' => 'Fajar', 'code' => 'FJ', 'start_time' => '05:00', 'end_time' => '13:00']);

        $this->putSchedule($owl, ['mode' => 'shift', 'shift_id' => $dawn->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', ShiftAssignmentService::UNLINKED_ATTENDANCE_MESSAGE);
        $this->putSchedule($owl, ['mode' => 'shift', 'shift_id' => $this->day->id])->assertOk();
    }

    /**
     * A check-in without a schedule (a cover waiting for approval) holds its number: a schedule added at
     * other hours gets the next free number, so it does not inherit the cover row and can be checked in to.
     */
    public function test_a_new_schedule_never_takes_the_number_of_a_check_in_without_a_schedule(): void
    {
        $gina = $this->freelancer('Gina');
        $this->schedule($gina, $this->day, $this->luucaOutlet);
        $cover = $this->cover($gina, 2, '15:10', '17:00');
        $evening = Shift::create(['company_id' => $this->company->id, 'name' => 'Malam Pendek', 'code' => 'MP', 'start_time' => '18:00', 'end_time' => '22:00']);

        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $evening->id, 'company_location_id' => $this->jchickenOutlet->id, 'add' => true])
            ->assertOk()
            ->assertJsonPath('data.sequence', 3);

        $pick = app(SessionResolver::class)->pickForCheckIn($gina->fresh(), Carbon::parse(self::DAY.' 18:00'));
        $this->assertSame(CheckInPick::SESSION, $pick->outcome);
        $this->assertSame(3, $pick->session->sequence);
        $this->assertNull($pick->attendance);
        $this->assertSame(2, $cover->fresh()->schedule_sequence);

        // A rejected check-in still holds its slot too, also on a day without any schedule.
        $rina = $this->freelancer('Rina');
        $this->cover($rina, 1, '07:00', '07:30', 'rejected');
        $this->putSchedule($rina, ['mode' => 'shift', 'shift_id' => $evening->id])->assertOk()->assertJsonPath('data.sequence', 2);
    }

    /**
     * Copy week agrees with assignDay(): a number a check-in of the target day holds (outside the copied
     * hours) is not inherited, the copy takes the lowest free number instead of skipping the day.
     */
    public function test_copying_gives_a_free_number_when_a_check_in_of_the_target_day_holds_the_copied_one(): void
    {
        $gina = $this->freelancer('Gina');
        $this->schedule($gina, $this->day, $this->luucaOutlet, date: '2026-11-10');
        $this->schedule($gina, $this->evening, $this->jchickenOutlet, date: '2026-11-10', sequence: 2);
        // Today, before any schedule: a morning cover at number 1, outside both copied shifts' hours.
        $this->cover($gina, 1, '05:00', '06:00');

        $result = $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-10', 'to' => self::DAY])->assertOk()->json('data');

        $this->assertSame([2, 0], [$result['copied'], $result['skipped_existing']]);
        $this->assertSame(
            [[2, $this->day->id], [3, $this->evening->id]],
            ShiftSchedule::where('freelancer_id', $gina->id)->whereDate('date', self::DAY)->orderBy('sequence')->get()->map(fn (ShiftSchedule $row): array => [$row->sequence, $row->shift_id])->all(),
        );
    }

    /**
     * The overlap check reaches past midnight: a shift of the day before that runs into this one, or a shift
     * of the next day that starts before this one ends, is refused too; touching ends are not an overlap.
     */
    public function test_a_schedule_overlapping_the_day_before_or_the_next_day_is_refused(): void
    {
        $gina = $this->freelancer('Gina');
        $early = Shift::create(['company_id' => $this->company->id, 'name' => 'Subuh', 'code' => 'SB', 'start_time' => '05:00', 'end_time' => '13:00']);
        $dawn = Shift::create(['company_id' => $this->company->id, 'name' => 'Fajar', 'code' => 'FJ', 'start_time' => '06:00', 'end_time' => '14:00']);
        $this->schedule($gina, $this->night, $this->luucaOutlet, date: '2026-11-18');

        // 22:00 - 06:00 of the 18th runs into 05:00 of the 19th.
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $early->id, 'company_location_id' => $this->luucaOutlet->id], '2026-11-19')
            ->assertUnprocessable()->assertJsonPath('errors.shift_id.0', ShiftAssignmentService::ADJACENT_OVERLAP_MESSAGE);
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $dawn->id, 'company_location_id' => $this->luucaOutlet->id], '2026-11-19')->assertOk();

        // A night shift on the 20th would run into the 21st's 05:00 start.
        $this->schedule($gina, $early, $this->luucaOutlet, date: '2026-11-21');
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $this->night->id, 'company_location_id' => $this->luucaOutlet->id], '2026-11-20')
            ->assertUnprocessable()->assertJsonPath('errors.shift_id.0', ShiftAssignmentService::ADJACENT_OVERLAP_MESSAGE);
        $this->putSchedule($gina, ['mode' => 'shift', 'shift_id' => $this->evening->id, 'company_location_id' => $this->luucaOutlet->id], '2026-11-20')->assertOk();

        $this->assertSame(4, ShiftSchedule::where('freelancer_id', $gina->id)->count());
    }

    /**
     * The neighbouring days are only asked when a row's hours change: a row saved before that rule, which
     * already overlaps the day before, can still get another section or outlet.
     */
    public function test_a_row_that_already_overlaps_a_neighbouring_day_can_still_be_edited_without_changing_its_hours(): void
    {
        $gina = $this->freelancer('Gina');
        $early = Shift::create(['company_id' => $this->company->id, 'name' => 'Subuh', 'code' => 'SB', 'start_time' => '05:00', 'end_time' => '13:00']);
        $this->schedule($gina, $this->night, $this->luucaOutlet, date: '2026-11-18');
        $legacy = $this->schedule($gina, $early, $this->luucaOutlet, date: '2026-11-19');

        $this->putSchedule($gina, ['mode' => 'shift', 'schedule_id' => $legacy->id, 'shift_id' => $early->id, 'company_location_id' => $this->luucaOutlet->id, 'section' => 'Kasir'], '2026-11-19')->assertOk();
        $this->assertSame('Kasir', $legacy->fresh()->section);

        app(ShiftAssignmentService::class)->updateSchedule($this->coordinator, $legacy->fresh(), ['company_location_id' => $this->jchickenOutlet->id, 'notes' => 'Pindah outlet'], source: 'panel');
        $this->assertSame([$this->jchickenOutlet->id, 'Pindah outlet'], [(int) $legacy->fresh()->company_location_id, $legacy->fresh()->notes]);

        // Changing its hours is still checked against the night before.
        $dawn = Shift::create(['company_id' => $this->company->id, 'name' => 'Fajar', 'code' => 'FJ', 'start_time' => '04:00', 'end_time' => '12:00']);
        $this->expectException(ValidationException::class);
        app(ShiftAssignmentService::class)->updateSchedule($this->coordinator, $legacy->fresh(), ['shift_id' => $dawn->id], source: 'panel');
    }

    /**
     * Copy week refuses a copy that would run into a working schedule of the day before or the next day,
     * at the target week's edges too (the Sunday before, the Monday after), and counts it apart.
     */
    public function test_copying_a_week_skips_a_copy_that_overlaps_the_day_before_or_after_the_week(): void
    {
        $gina = $this->freelancer('Gina');
        $early = Shift::create(['company_id' => $this->company->id, 'name' => 'Subuh', 'code' => 'SB', 'start_time' => '05:00', 'end_time' => '13:00']);
        // Source week 9-15 November: Monday at 05:00, Wednesday day shift, Sunday night.
        $this->schedule($gina, $early, $this->luucaOutlet, date: '2026-11-09');
        $this->schedule($gina, $this->day, $this->luucaOutlet, date: '2026-11-11');
        $this->schedule($gina, $this->night, $this->luucaOutlet, date: '2026-11-15');
        // Around the target week 23-29 November: a night shift the Sunday before, a 05:00 start the Monday after.
        $this->schedule($gina, $this->night, $this->luucaOutlet, date: '2026-11-22');
        $this->schedule($gina, $early, $this->luucaOutlet, date: '2026-11-30');

        $result = $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-09', 'to' => '2026-11-23'])->assertOk()->json('data');

        $this->assertSame([1, 2], [$result['copied'], $result['skipped_overlap']]);
        $this->assertSame(['2026-11-25'], ShiftSchedule::where('freelancer_id', $gina->id)->where('date', '>=', '2026-11-23')->where('date', '<', '2026-11-30')->get()->map(fn (ShiftSchedule $row): string => $row->date->toDateString())->all());
        $this->assertStringContainsString('2 dilewati karena jam shiftnya bertabrakan', app(ScheduleCopyService::class)->summary($result));
    }

    /**
     * Copy week reads the target week's schedules, check-ins and leave once per kind of worker, not once
     * per copied schedule; a rejected check-in on the target day does not stop the copy.
     */
    public function test_copying_a_week_reads_the_target_week_in_bulk(): void
    {
        $copyWeekOf = function (int $count): array {
            ShiftSchedule::query()->delete();

            foreach (range(1, $count) as $index) {
                $worker = $this->freelancer('Pekerja '.uniqid());
                $this->schedule($worker, $this->day, $this->luucaOutlet, date: '2026-11-10');
                $this->schedule($worker, $this->evening, $this->jchickenOutlet, date: '2026-11-11');
                $this->cover($worker, 1, '08:00', '09:00', 'rejected', date: '2026-11-18');
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $this->postJson('/api/v1/team/schedules/copy', ['from' => '2026-11-10', 'to' => self::DAY])->assertOk()->json('data');
            $queries = collect(DB::getQueryLog())->pluck('query');
            DB::disableQueryLog();

            return [
                $result['copied'],
                $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "leave_requests"'))->count(),
                $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "attendances"'))->count(),
            ];
        };

        [$copiedFew, $leaveFew, $attendanceFew] = $copyWeekOf(2);
        [$copiedMany, $leaveMany, $attendanceMany] = $copyWeekOf(6);

        $this->assertSame([4, 12], [$copiedFew, $copiedMany], 'Every schedule is copied, the one over a rejected check-in included.');
        $this->assertSame([1, 1], [$leaveFew, $leaveMany]);
        $this->assertSame([1, 1], [$attendanceFew, $attendanceMany]);
    }

    // ── Reading several schedules ──────────────────────────────────────────

    public function test_the_week_keeps_one_cell_per_day_and_lists_every_schedule(): void
    {
        $gina = $this->freelancer('Gina');
        $evening = $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 1);
        $morning = $this->schedule($gina, $this->day, $this->luucaOutlet, sequence: 2);
        $this->attend($gina, $morning, '07:58', sequence: 2);

        $worker = $this->getJson('/api/v1/team/week?date='.self::DAY)->assertOk()->json('workers.0');
        $cell = $worker['days'][self::DAY];

        $this->assertFalse(array_is_list($cell), 'Still one object per day.');
        $this->assertSame([$morning->id, 1, true, false], [$cell['schedule_id'], $cell['more'], $cell['locked'], $cell['on_leave']], 'The earliest start is the primary cell.');
        $this->assertSame([$morning->id, $evening->id], array_column($worker['day_schedules'][self::DAY], 'schedule_id'));
        $this->assertSame([true, false], array_column($worker['day_schedules'][self::DAY], 'locked'));
        $this->assertSame([2, 1], array_column($worker['day_schedules'][self::DAY], 'sequence'));
    }

    public function test_the_roster_shows_each_schedule_with_its_own_attendance_and_counts_the_person_once(): void
    {
        $this->travelTo(Carbon::parse(self::DAY.' 16:30'));
        $gina = $this->freelancer('Gina');
        $luuca = $this->schedule($gina, $this->day, $this->luucaOutlet);
        $jchicken = $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 2);
        $this->attend($gina, $luuca, '07:58', '15:05');
        $this->attend($gina, $jchicken, '16:10', status: 'late', lateMinutes: 10, sequence: 2);
        $dina = $this->freelancer('Dina');
        $this->schedule($dina, $this->evening, $this->jchickenOutlet);

        $response = $this->getJson('/api/v1/team/roster?date='.self::DAY)->assertOk();
        $outlets = collect($response->json('outlets'))->keyBy('location.name');

        $atLuuca = collect($outlets['Luuca Ciledug']['entries'])->firstWhere('worker.name', 'Gina');
        $atJchicken = collect($outlets['JChicken Ciledug']['entries'])->firstWhere('worker.name', 'Gina');

        $this->assertSame([$luuca->id, 'pulang', '07:58', '15:05', 0], [$atLuuca['schedule_id'], $atLuuca['state'], $atLuuca['check_in'], $atLuuca['check_out'], $atLuuca['late_minutes']]);
        $this->assertSame([$jchicken->id, 'terlambat', '16:10', null, 10], [$atJchicken['schedule_id'], $atJchicken['state'], $atJchicken['check_in'], $atJchicken['check_out'], $atJchicken['late_minutes']]);
        $this->assertCount(1, $atLuuca['attendances']);
        $this->assertSame(['Luuca Ciledug', true, 2, false], [$atLuuca['outlet_name'], $atLuuca['locked'], $atLuuca['schedule_count'], $atLuuca['is_missing_check_out']]);
        $this->assertSame(0, $atLuuca['overtime_minutes']);

        // Counts stay per schedule; the summary counts distinct people.
        $this->assertSame([1, 1], [$outlets['Luuca Ciledug']['counts']['scheduled'], $outlets['Luuca Ciledug']['counts']['checked_in']]);
        $this->assertSame([2, 1], [$outlets['JChicken Ciledug']['counts']['scheduled'], $outlets['JChicken Ciledug']['counts']['checked_in']]);
        $this->assertSame(['people_scheduled' => 2, 'people_checked_in' => 1], $response->json('summary'));
    }

    public function test_a_row_without_a_link_on_a_two_schedule_day_is_listed_apart(): void
    {
        $this->travelTo(Carbon::parse(self::DAY.' 10:00'));
        $gina = $this->freelancer('Gina');
        $this->schedule($gina, $this->day, $this->luucaOutlet);
        $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 2);
        Attendance::create(['freelancer_id' => $gina->id, 'date' => self::DAY, 'check_in' => self::DAY.' 08:00:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->luucaOutlet->id]);

        $outlets = collect($this->getJson('/api/v1/team/roster?date='.self::DAY)->assertOk()->json('outlets'));

        $this->assertSame([null, null], $outlets->whereNotNull('location')->flatMap(fn (array $outlet): array => $outlet['entries'])->pluck('check_in')->all(), 'It belongs to neither schedule.');
        $this->assertSame(['Gina'], collect($outlets->firstWhere('kind', 'unscheduled')['entries'])->pluck('worker.name')->all());
    }

    public function test_split_break_and_last_night_notes_land_on_the_right_schedule(): void
    {
        $this->travelTo(Carbon::parse(self::DAY.' 14:30'));
        $gina = $this->freelancer('Gina');
        $split = $this->schedule($gina, $this->split, $this->luucaOutlet);
        $short = Shift::create(['company_id' => $this->company->id, 'name' => 'Bantu Siang', 'code' => 'BS', 'start_time' => '15:00', 'end_time' => '17:00']);
        $this->schedule($gina, $short, $this->jchickenOutlet, sequence: 2);
        $this->attend($gina, $split, '07:45', '13:00');

        $entries = collect($this->getJson('/api/v1/team/roster?date='.self::DAY)->json('outlets'))->flatMap(fn (array $outlet): array => $outlet['entries'])->keyBy('outlet_name');
        $this->assertSame(['jeda', 'Jeda · segmen 2 mulai 17:45'], [$entries['Luuca Ciledug']['state'], $entries['Luuca Ciledug']['note']]);
        $this->assertSame('nanti', $entries['JChicken Ciledug']['state']);
        $this->assertArrayNotHasKey('note', $entries['JChicken Ciledug']);

        // Last night's shift still running concerns the first schedule of today only.
        $this->travelTo(Carbon::parse('2026-11-18 02:00'));
        $eko = $this->freelancer('Eko');
        $lastNight = $this->schedule($eko, $this->night, $this->jchickenOutlet, date: '2026-11-17');
        $this->attend($eko, $lastNight, '21:55');
        $this->schedule($eko, $this->day, $this->luucaOutlet, date: '2026-11-18');
        $this->schedule($eko, $this->evening, $this->jchickenOutlet, date: '2026-11-18', sequence: 2);

        $today = collect($this->getJson('/api/v1/team/roster')->json('outlets'))->flatMap(fn (array $outlet): array => $outlet['entries'])->where('worker.name', 'Eko')->keyBy('outlet_name');
        $this->assertSame(['hadir', '21:55'], [$today['Luuca Ciledug']['state'], $today['Luuca Ciledug']['check_in']]);
        $this->assertStringStartsWith('Shift semalam', $today['Luuca Ciledug']['note']);
        $this->assertSame(['nanti', null], [$today['JChicken Ciledug']['state'], $today['JChicken Ciledug']['check_in']]);
        $this->assertArrayNotHasKey('note', $today['JChicken Ciledug']);
    }

    public function test_the_dashboard_widget_shows_the_numbers_of_the_api_roster(): void
    {
        $this->travelTo(Carbon::parse(self::DAY.' 16:30'));
        $gina = $this->freelancer('Gina');
        $luuca = $this->schedule($gina, $this->day, $this->luucaOutlet);
        $jchicken = $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 2);
        $this->attend($gina, $luuca, '07:58', '15:05');
        $this->attend($gina, $jchicken, '16:10', status: 'late', lateMinutes: 10, sequence: 2);
        $this->schedule($this->freelancer('Dina'), $this->evening, $this->jchickenOutlet);
        $walkIn = $this->freelancer('Fajar');
        Attendance::create(['freelancer_id' => $walkIn->id, 'date' => self::DAY, 'check_in' => self::DAY.' 16:00:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->jchickenOutlet->id]);

        $response = $this->getJson('/api/v1/team/roster?date='.self::DAY)->assertOk();
        $totals = collect($response->json('outlets'))->reduce(function (array $sum, array $outlet): array {
            foreach ($outlet['counts'] as $key => $count) {
                $sum[$key] = ($sum[$key] ?? 0) + $count;
            }

            return $sum;
        }, []);

        $this->actingAs($this->coordinator);
        Livewire::test(OutletTodayWidget::class)
            ->assertSee("{$totals['scheduled']} terjadwal · ".($totals['hadir'] + $totals['pulang'])." hadir · {$totals['terlambat']} terlambat · {$totals['belum']} belum absen")
            ->assertSee("{$response->json('summary.people_checked_in')} dari {$response->json('summary.people_scheduled')} orang sudah absen")
            ->assertSee('Tanpa jadwal')
            ->assertSee('Fajar');
    }

    // ── Copy, swap and reminders ───────────────────────────────────────────

    public function test_copying_a_week_copies_every_schedule_of_a_day_and_never_merges(): void
    {
        $gina = $this->freelancer('Gina');
        $this->schedule($gina, $this->day, $this->luucaOutlet);
        $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 2);
        $dina = $this->freelancer('Dina');
        $this->schedule($dina, $this->day, $this->luucaOutlet);
        $this->schedule($dina, $this->evening, $this->jchickenOutlet, sequence: 2);
        // Dina's target day is already planned: it is left as it is, not merged.
        $this->schedule($dina, $this->afternoon, $this->jchickenOutlet, date: '2026-11-24');

        $result = $this->postJson('/api/v1/team/schedules/copy', ['from' => self::DAY, 'to' => '2026-11-24'])->assertOk()->json('data');

        $this->assertSame([2, 2], [$result['copied'], $result['skipped_existing']]);
        $this->assertSame(
            [[1, $this->day->id, $this->luucaOutlet->id], [2, $this->evening->id, $this->jchickenOutlet->id]],
            ShiftSchedule::where('freelancer_id', $gina->id)->whereDate('date', '2026-11-24')->orderBy('sequence')->get()->map(fn (ShiftSchedule $row): array => [$row->sequence, $row->shift_id, $row->company_location_id])->all(),
        );
        $this->assertSame([$this->afternoon->id], ShiftSchedule::where('freelancer_id', $dina->id)->whereDate('date', '2026-11-24')->pluck('shift_id')->all());
    }

    public function test_a_swap_is_refused_on_a_day_with_two_schedules(): void
    {
        $andi = $this->freelancer('Andi', withAccount: true);
        $budi = $this->freelancer('Budi', withAccount: true);
        $this->schedule($andi, $this->day, $this->luucaOutlet, date: '2026-11-19');
        $this->schedule($andi, $this->evening, $this->jchickenOutlet, date: '2026-11-19', sequence: 2);
        $this->schedule($budi, $this->afternoon, $this->jchickenOutlet, date: '2026-11-19');

        $swaps = app(ShiftSwapService::class);
        $this->assertSame(ShiftSwapService::MULTI_SCHEDULE_MESSAGE, $swaps->eligibilityError($budi, $andi, 'swap', Carbon::parse('2026-11-19'), Carbon::parse('2026-11-19')));
        $this->assertSame(ShiftSwapService::MULTI_SCHEDULE_MESSAGE, $swaps->eligibilityError($andi, $budi, 'swap', Carbon::parse('2026-11-19'), Carbon::parse('2026-11-19')));

        ShiftSchedule::where('freelancer_id', $andi->id)->where('sequence', 2)->delete();
        $this->assertNull($swaps->eligibilityError($andi, $budi, 'swap', Carbon::parse('2026-11-19'), Carbon::parse('2026-11-19')));
    }

    public function test_reminders_go_out_for_each_schedule_of_the_day(): void
    {
        Notification::fake();
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        $gina = $this->freelancer('Gina', withAccount: true);
        $luuca = $this->schedule($gina, $this->day, $this->luucaOutlet);
        $this->schedule($gina, $this->evening, $this->jchickenOutlet, sequence: 2);
        $reminders = app(AttendanceReminderService::class);

        $this->travelTo(Carbon::parse(self::DAY.' 07:45'));
        $this->assertSame(1, $reminders->sendDue(now())['shift_soon']);
        $this->attend($gina, $luuca, '07:55', '15:00');

        $this->travelTo(Carbon::parse(self::DAY.' 15:45'));
        $this->assertSame(1, $reminders->sendDue(now())['shift_soon'], 'The second schedule has its own reminder.');
        $this->assertSame(0, $reminders->sendDue(now())['shift_soon'], 'Not sent twice.');
        Notification::assertSentTo($gina->user, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => $n->title === 'Shift JChicken Sore mulai 16:00' && str_contains($n->body, 'JChicken Ciledug'));

        // The first schedule's attendance does not count for the second one.
        $this->travelTo(Carbon::parse(self::DAY.' 16:16'));
        $this->assertSame(1, $reminders->sendDue(now())['not_checked_in']);
        $this->assertEqualsCanonicalizing(['shift_soon', 'shift_soon_s2', 'not_checked_in_s2'], DB::table('attendance_reminders')->pluck('kind')->all());
    }

    /**
     * The scheduler runs every 5 minutes over the whole workforce: the schedules and first roster dates are
     * read in bulk, so the number of queries does not grow with the number of workers.
     */
    public function test_reminders_read_the_schedules_in_bulk(): void
    {
        Notification::fake();
        $reminders = app(AttendanceReminderService::class);
        $addWorkers = function (int $count): void {
            foreach (range(1, $count) as $index) {
                $worker = $this->freelancer('Pekerja '.uniqid(), withAccount: true);
                $this->schedule($worker, $this->day, $this->luucaOutlet);
                $this->schedule($worker, $this->evening, $this->jchickenOutlet, sequence: 2);
                $this->freelancer('Tetap '.uniqid(), ['shift_id' => $this->day->id], withAccount: true);
            }
        };
        $queriesAtNoon = function () use ($reminders): int {
            $this->travelTo(Carbon::parse(self::DAY.' 12:00'));
            // Each run of the scheduler is its own process: nothing is remembered from the run before.
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertSame(0, array_sum($reminders->sendDue(now())), 'Nothing is due at noon.');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $addWorkers(2);
        $few = $queriesAtNoon();
        $addWorkers(5);

        $this->assertSame($few, $queriesAtNoon());
    }

    /**
     * Carried must-fix (c): a check-out the approver rejected cannot be made again, so no reminder offers it.
     */
    public function test_a_rejected_check_out_gets_a_correction_reminder_instead_of_check_out(): void
    {
        Notification::fake();
        config(['services.onesignal.app_id' => 'app-id', 'services.onesignal.rest_api_key' => 'rest-key']);
        $gina = $this->freelancer('Gina', withAccount: true);
        $luuca = $this->schedule($gina, $this->day, $this->luucaOutlet);
        $attendance = $this->attend($gina, $luuca, '07:55');
        $attendance->forceFill(['check_out_rejected_at' => self::DAY.' 14:50:00', 'rejection_reason' => 'Di luar outlet'])->save();
        $reminders = app(AttendanceReminderService::class);

        $this->travelTo(Carbon::parse(self::DAY.' 15:00'));
        $this->assertSame(0, $reminders->sendDue(now())['time_to_leave']);

        $this->travelTo(Carbon::parse(self::DAY.' 17:05'));
        $this->assertSame(1, $reminders->sendDue(now())['forgot_check_out']);
        Notification::assertSentTo($gina->user, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => $n->title === 'Absen pulang ditolak' && $n->data['action'] === 'correction');
        Notification::assertNotSentTo($gina->user, AttendanceReminderNotification::class, fn (AttendanceReminderNotification $n): bool => $n->data['action'] === 'check_out');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    private function putSchedule(Freelancer $worker, array $data, string $date = self::DAY): TestResponse
    {
        return $this->putJson('/api/v1/team/schedules', ['worker_type' => 'freelancer', 'worker_id' => $worker->id, 'date' => $date] + $data);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function freelancer(string $name, array $attributes = [], bool $withAccount = false): Freelancer
    {
        if ($withAccount) {
            $user = User::factory()->create(['name' => $name, 'is_active' => true]);
            $user->assignRole('Freelancer');
            $attributes['user_id'] = $user->id;
        }

        return Freelancer::create($attributes + ['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'full_name' => $name, 'status' => 'active']);
    }

    private function schedule(Freelancer $worker, Shift $shift, CompanyLocation $outlet, string $date = self::DAY, int $sequence = 1): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'sequence' => $sequence, 'shift_id' => $shift->id, 'company_location_id' => $outlet->id]);
    }

    /**
     * A check-in without a schedule link (an off-schedule cover) at the given number.
     */
    private function cover(Freelancer $worker, int $sequence, string $in, ?string $out = null, string $status = 'pending_approval', string $date = self::DAY): Attendance
    {
        return Attendance::create([
            'freelancer_id' => $worker->id,
            'date' => $date,
            'schedule_sequence' => $sequence,
            'segment' => 1,
            'company_location_id' => $this->jchickenOutlet->id,
            'check_in' => "{$date} {$in}:00",
            'check_out' => $out ? "{$date} {$out}:00" : null,
            'status' => $status,
            'late_minutes' => 0,
            'is_off_schedule' => true,
        ]);
    }

    private function attend(Freelancer $worker, ShiftSchedule $schedule, string $in, ?string $out = null, string $status = 'on_time', int $lateMinutes = 0, int $sequence = 1): Attendance
    {
        $date = $schedule->date->toDateString();
        $crosses = $out !== null && $out < $in;

        return Attendance::create([
            'freelancer_id' => $worker->id,
            'date' => $date,
            'schedule_sequence' => $sequence,
            'segment' => 1,
            'shift_schedule_id' => $schedule->id,
            'shift_id' => $schedule->shift_id,
            'company_location_id' => $schedule->company_location_id,
            'check_in' => "{$date} {$in}:00",
            'check_out' => $out ? Carbon::parse("{$date} {$out}:00")->addDays($crosses ? 1 : 0)->toDateTimeString() : null,
            'status' => $status,
            'late_minutes' => $lateMinutes,
        ]);
    }
}
