<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The coordinator's "Hari" view on a real day: the header count agrees with the rows, a split shift
 * between its segments is on a break, last night's shift still running after midnight is working, and
 * people who checked in without a schedule are listed under "Tanpa jadwal".
 */
class TeamRosterLiveStateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $jchicken;

    private CompanyLocation $outlet;

    private CompanyLocation $otherOutlet;

    private CompanyLocation $momoyoOutlet;

    private Division $momoyo;

    private Shift $morning;

    private Shift $split;

    private Shift $night;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        foreach (['Koordinator', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $luuca = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->momoyo = Division::create(['company_id' => $this->company->id, 'name' => 'MOMOYO', 'is_active' => true]);
        $this->outlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->otherOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $luuca->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->momoyoOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->momoyo->id, 'name' => 'Momoyo Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);

        $this->morning = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'Pagi', 'code' => 'P', 'start_time' => '08:00', 'end_time' => '17:00']);
        $this->split = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'Split', 'code' => 'SP', 'start_time' => '07:45', 'end_time' => '13:00', 'second_start_time' => '17:45', 'second_end_time' => '22:00']);
        $this->night = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'Malam', 'code' => 'M', 'start_time' => '22:00', 'end_time' => '06:00']);

        $coordinator = User::factory()->create(['name' => 'Loocita']);
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id, $luuca->id]);
        Sanctum::actingAs($coordinator);
    }

    public function test_header_counts_agree_with_the_rows_including_pending_and_split_break(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 14:00'));
        $onTime = $this->scheduled('Ani', $this->morning);
        $pending = $this->scheduled('Budi', $this->morning);
        $split = $this->scheduled('Citra', $this->split);
        $this->scheduled('Dodi', $this->morning);

        $this->attend($onTime, '2026-11-17', '2026-11-17 07:55', status: 'on_time');
        $this->attend($pending, '2026-11-17', '2026-11-17 08:40', status: 'pending_approval');
        $this->attend($split, '2026-11-17', '2026-11-17 07:50', '2026-11-17 13:00', shift: $this->split);

        $outlet = $this->roster()->assertOk()->assertJsonCount(1, 'outlets')->json('outlets.0');

        $this->assertSame(4, $outlet['counts']['scheduled']);
        $this->assertSame(3, $outlet['counts']['checked_in'], 'Pending and on-break people have checked in too.');
        $this->assertSame(1, $outlet['counts']['jeda']);
        $this->assertSame(1, $outlet['counts']['menunggu']);
        $this->assertSame(1, $outlet['counts']['belum']);
        $this->assertSame(3, collect($outlet['entries'])->whereNotNull('check_in')->count(), 'The count is the rows with a check-in.');

        $citra = $this->entryOf($outlet, 'Citra');
        $this->assertSame('jeda', $citra['state']);
        $this->assertSame('Jeda · segmen 2 mulai 17:45', $citra['note']);
        $this->assertSame('07:50', $citra['check_in']);
    }

    public function test_split_shift_missing_segment_two_is_late_today_and_absent_on_past_days(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 18:10'));
        $split = $this->scheduled('Citra', $this->split);
        $this->attend($split, '2026-11-17', '2026-11-17 07:50', '2026-11-17 13:00', shift: $this->split);

        $citra = $this->entryOf($this->roster()->json('outlets.0'), 'Citra');
        $this->assertSame('belum', $citra['state']);
        $this->assertSame('Belum absen segmen 2 (mulai 17:45)', $citra['note']);
        $counts = $this->roster()->json('outlets.0.counts');
        $this->assertSame(0, $counts['checked_in'], 'Missing segment 2 is "belum" only, not also "sudah absen".');
        $this->assertSame(1, $counts['belum']);

        $this->travelTo(Carbon::parse('2026-11-18 09:00'));
        $this->assertSame('Tidak absen segmen 2', $this->entryOf($this->roster('2026-11-17')->json('outlets.0'), 'Citra')['note']);
    }

    public function test_split_shift_in_segment_two_or_finished_keeps_the_roster_state(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 22:30'));
        $split = $this->scheduled('Citra', $this->split);
        $this->attend($split, '2026-11-17', '2026-11-17 07:50', '2026-11-17 13:00', shift: $this->split);
        $this->attend($split, '2026-11-17', '2026-11-17 17:40', '2026-11-17 22:05', shift: $this->split, segment: 2);

        $citra = $this->entryOf($this->roster()->json('outlets.0'), 'Citra');
        $this->assertSame('pulang', $citra['state']);
        $this->assertArrayNotHasKey('note', $citra);
    }

    public function test_night_shift_checked_in_after_midnight_is_working_on_today_and_yesterday(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 02:00'));
        $night = $this->scheduled('Eko', $this->night, '2026-11-16');
        $this->scheduleOn($night, $this->night, '2026-11-17');
        $this->attend($night, '2026-11-16', '2026-11-17 00:30', status: 'late', lateMinutes: 150, shift: $this->night);

        $today = $this->roster()->json('outlets.0');
        $eko = $this->entryOf($today, 'Eko');
        $this->assertSame('terlambat', $eko['state'], 'Not "Belum mulai": Eko is on last night\'s shift.');
        $this->assertSame('00:30', $eko['check_in']);
        $this->assertStringStartsWith('Shift semalam', $eko['note']);
        $this->assertSame(1, $today['counts']['checked_in']);

        $yesterday = $this->entryOf($this->roster('2026-11-16')->json('outlets.0'), 'Eko');
        $this->assertSame('terlambat', $yesterday['state']);
        $this->assertSame('00:30', $yesterday['check_in']);
    }

    public function test_night_shift_without_a_schedule_today_is_listed_as_last_night(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 03:00'));
        $night = $this->scheduled('Eko', $this->night, '2026-11-16');
        $this->attend($night, '2026-11-16', '2026-11-16 21:55', shift: $this->night);

        $this->roster()
            ->assertOk()
            ->assertJsonCount(1, 'outlets')
            ->assertJsonPath('outlets.0.kind', 'overnight')
            ->assertJsonPath('outlets.0.counts.scheduled', 0)
            ->assertJsonPath('outlets.0.entries.0.worker.name', 'Eko')
            ->assertJsonPath('outlets.0.entries.0.state', 'hadir')
            ->assertJsonPath('outlets.0.entries.0.check_in', '21:55')
            ->assertJsonPath('outlets.0.entries.0.schedule_id', null);
    }

    public function test_night_worker_with_a_day_off_today_is_listed_with_last_night_while_on_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 02:00'));
        $eko = $this->scheduled('Eko', $this->night, '2026-11-16');
        ShiftSchedule::create(['freelancer_id' => $eko->id, 'date' => '2026-11-17', 'is_day_off' => true]);
        $this->attend($eko, '2026-11-16', '2026-11-16 21:55', shift: $this->night);

        $this->roster()
            ->assertOk()
            ->assertJsonCount(1, 'outlets')
            ->assertJsonPath('outlets.0.kind', 'overnight')
            ->assertJsonPath('outlets.0.counts.scheduled', 0)
            ->assertJsonPath('outlets.0.entries.0.worker.name', 'Eko')
            ->assertJsonPath('outlets.0.entries.0.state', 'hadir')
            ->assertJsonPath('outlets.0.entries.0.check_in', '21:55')
            ->assertJsonPath('outlets.0.entries.0.note', 'Shift semalam (16 Nov) · belum absen pulang · hari ini libur');

        // Long after the night shift ended the open row is a forgotten check-out: back to "Libur" with a note.
        $this->travelTo(Carbon::parse('2026-11-17 11:00'));
        $outlet = $this->roster()->assertJsonCount(1, 'outlets')->json('outlets.0');
        $this->assertArrayNotHasKey('kind', $outlet);
        $this->assertSame('libur', $outlet['entries'][0]['state']);
        $this->assertNull($outlet['entries'][0]['check_in']);
        $this->assertSame('Shift semalam (16 Nov) belum absen pulang', $outlet['entries'][0]['note']);
    }

    public function test_night_worker_on_leave_today_is_listed_with_last_night_while_on_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 02:00'));
        $eko = $this->scheduled('Eko', $this->night, '2026-11-16');
        $this->scheduleOn($eko, $this->morning, '2026-11-17');
        Attendance::create(['freelancer_id' => $eko->id, 'date' => '2026-11-17', 'status' => 'leave']);
        $this->attend($eko, '2026-11-16', '2026-11-16 22:00', shift: $this->night);

        $response = $this->roster()->assertOk();
        $group = collect($response->json('outlets'))->firstWhere('kind', 'overnight');

        $this->assertNotNull($group, 'Eko is on last night\'s shift, not only "Cuti".');
        $this->assertSame('22:00', $group['entries'][0]['check_in']);
        $this->assertStringEndsWith('hari ini cuti', $group['entries'][0]['note']);
        $this->assertFalse(collect($response->json('outlets'))->flatMap(fn (array $outlet) => $outlet['entries'])->contains('state', 'cuti'), 'Eko is listed once.');
    }

    public function test_forgotten_overnight_check_out_does_not_hide_a_missed_shift_today(): void
    {
        $afternoon = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'Siang', 'code' => 'S', 'start_time' => '14:00', 'end_time' => '22:00']);
        $this->travelTo(Carbon::parse('2026-11-17 15:00'));
        $eko = $this->scheduled('Eko', $this->night, '2026-11-16');
        $this->scheduleOn($eko, $afternoon, '2026-11-17');
        $this->attend($eko, '2026-11-16', '2026-11-16 22:00', shift: $this->night);

        $outlet = $this->roster()->assertJsonCount(1, 'outlets')->json('outlets.0');
        $eko = $this->entryOf($outlet, 'Eko');

        $this->assertSame('belum', $eko['state']);
        $this->assertNull($eko['check_in'], 'Yesterday\'s check-in is not today\'s.');
        $this->assertSame('Shift semalam (16 Nov) belum absen pulang', $eko['note']);
        $this->assertSame(0, $outlet['counts']['checked_in']);
        $this->assertSame(1, $outlet['counts']['belum']);
    }

    public function test_forgotten_overnight_check_out_long_after_the_shift_is_not_working_before_tonight(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 11:00'));
        $eko = $this->scheduled('Eko', $this->night, '2026-11-16');
        $this->scheduleOn($eko, $this->night, '2026-11-17');
        $this->attend($eko, '2026-11-16', '2026-11-16 22:00', shift: $this->night);

        $outlet = $this->roster()->json('outlets.0');
        $eko = $this->entryOf($outlet, 'Eko');

        $this->assertSame('nanti', $eko['state'], '06:00 + grace has passed: Eko forgot to check out, he is not at work.');
        $this->assertSame('Shift semalam (16 Nov) belum absen pulang', $eko['note']);
        $this->assertSame(0, $outlet['counts']['checked_in']);
    }

    public function test_closed_stale_or_day_shift_rows_of_yesterday_are_not_working_today(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 02:00'));
        $closed = $this->scheduled('Closed', $this->night, '2026-11-16');
        $this->scheduleOn($closed, $this->night, '2026-11-17');
        $this->attend($closed, '2026-11-16', '2026-11-16 22:00', '2026-11-17 01:30', shift: $this->night);
        $forgotDayShift = $this->scheduled('Lupa', $this->morning, '2026-11-16');
        $this->scheduleOn($forgotDayShift, $this->morning, '2026-11-17');
        $this->attend($forgotDayShift, '2026-11-16', '2026-11-16 08:00', shift: $this->morning);

        $outlet = $this->roster()->json('outlets.0');
        $this->assertSame('pulang', $this->entryOf($this->roster('2026-11-16')->json('outlets.0'), 'Closed')['state']);
        $this->assertSame('nanti', $this->entryOf($outlet, 'Closed')['state']);
        $this->assertSame('nanti', $this->entryOf($outlet, 'Lupa')['state'], 'A forgotten day-shift check-out is not "working" today.');
        $this->assertSame(0, $outlet['counts']['checked_in']);

        // A night row checked in more than 20 hours ago no longer counts as still running.
        $this->travelTo(Carbon::parse('2026-11-17 18:30'));
        $this->assertSame('nanti', $this->entryOf($this->roster()->json('outlets.0'), 'Closed')['state']);
    }

    public function test_check_in_without_a_schedule_is_listed_under_tanpa_jadwal(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        $this->scheduled('Ani', $this->morning);
        $walkIn = $this->freelancer('Fajar');
        $this->attend($walkIn, '2026-11-17', '2026-11-17 08:05', status: 'late', lateMinutes: 5);
        $onLeave = $this->freelancer('Gita');
        Attendance::create(['freelancer_id' => $onLeave->id, 'date' => '2026-11-17', 'status' => 'leave']);
        $rejected = $this->freelancer('Hadi');
        $this->attend($rejected, '2026-11-17', '2026-11-17 08:00', status: 'rejected');
        $outsider = $this->freelancer('Orang Momoyo', $this->momoyo);
        $this->attend($outsider, '2026-11-17', '2026-11-17 08:00', location: $this->momoyoOutlet);

        $response = $this->roster()->assertOk()->assertJsonCount(2, 'outlets');
        $group = $response->json('outlets.1');

        $this->assertSame('unscheduled', $group['kind']);
        $this->assertNull($group['location']);
        $this->assertSame(0, $group['counts']['scheduled']);
        $this->assertSame(['Fajar'], collect($group['entries'])->pluck('worker.name')->all(), 'Leave, rejected and other brands are not listed.');
        $this->assertSame('terlambat', $group['entries'][0]['state']);
        $this->assertSame('Tanpa jadwal', $group['entries'][0]['note']);
        $this->assertSame('08:05', $group['entries'][0]['check_in']);
        $this->assertTrue($group['entries'][0]['can_edit']);
        $this->assertSame(0, $response->json('outlets.0.counts.checked_in'), 'The scheduled outlet counts only its own rows.');

        // The same person on yesterday's view once the day is over.
        $this->travelTo(Carbon::parse('2026-11-18 09:00'));
        $this->roster('2026-11-17')->assertJsonPath('outlets.1.entries.0.worker.name', 'Fajar');
    }

    /**
     * Real-device QA (17 Nov 2026): a rostered freelancer whose out-of-area check-in the PIC refused
     * read "Hadir" and counted as checked in; people on their default shift read "Tanpa jadwal".
     */
    public function test_refused_check_in_reads_ditolak_and_a_default_shift_is_not_called_tanpa_jadwal(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 10:40'));
        $hana = $this->scheduled('Hana', $this->morning);
        $this->attend($hana, '2026-11-17', '2026-11-17 08:30', status: 'rejected', shift: $this->morning);
        $ani = $this->scheduled('Ani', $this->morning);
        $this->attend($ani, '2026-11-17', '2026-11-17 07:55', shift: $this->morning);
        $eka = $this->freelancer('Eka');
        $eka->update(['shift_id' => $this->morning->id]);
        $this->attend($eka, '2026-11-17', '2026-11-17 07:58', shift: $this->morning);

        $response = $this->roster()->assertOk();
        $outlet = $response->json('outlets.0');

        $this->assertSame('ditolak', $this->entryOf($outlet, 'Hana')['state']);
        $this->assertSame(1, $outlet['counts']['checked_in'], 'Only Ani counts; the refused check-in is not a presence.');
        $this->assertSame(1, $outlet['counts']['ditolak']);

        $group = collect($response->json('outlets'))->firstWhere('kind', 'unscheduled');
        $this->assertSame('Shift default', $this->entryOf($group, 'Eka')['note']);
    }

    public function test_tanpa_jadwal_follows_the_outlet_filter(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        $here = $this->freelancer('Di Sini');
        $this->attend($here, '2026-11-17', '2026-11-17 08:00', location: $this->outlet);
        $there = $this->freelancer('Di Sana');
        $this->attend($there, '2026-11-17', '2026-11-17 08:00', location: $this->otherOutlet);

        $this->getJson('/api/v1/team/roster?location_id='.$this->outlet->id)
            ->assertOk()
            ->assertJsonPath('outlets.0.kind', 'unscheduled')
            ->assertJsonPath('outlets.0.entries.*.worker.name', ['Di Sini']);
    }

    public function test_check_in_on_a_day_off_stays_libur_with_a_note(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));
        $worker = $this->freelancer('Ira');
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => '2026-11-17', 'is_day_off' => true]);
        $this->attend($worker, '2026-11-17', '2026-11-17 08:00');

        $outlet = $this->roster()->assertJsonCount(1, 'outlets')->json('outlets.0');
        $this->assertSame('libur', $outlet['entries'][0]['state']);
        $this->assertSame('Masuk di hari libur', $outlet['entries'][0]['note']);
        $this->assertSame(0, $outlet['counts']['scheduled']);
        $this->assertSame(0, $outlet['counts']['checked_in']);
    }

    public function test_an_empty_day_has_no_groups(): void
    {
        $this->travelTo(Carbon::parse('2026-11-17 10:00'));

        $this->roster()->assertOk()->assertJsonCount(0, 'outlets');
    }

    private function roster(?string $date = null): TestResponse
    {
        return $this->getJson('/api/v1/team/roster'.($date ? "?date={$date}" : ''));
    }

    /**
     * @param  array{entries: list<array<string, mixed>>}  $outlet
     * @return array<string, mixed>
     */
    private function entryOf(array $outlet, string $name): array
    {
        $entry = collect($outlet['entries'])->firstWhere('worker.name', $name);
        $this->assertNotNull($entry, "{$name} is on the roster.");

        return $entry;
    }

    private function freelancer(string $name, ?Division $division = null): Freelancer
    {
        return Freelancer::create(['company_id' => $this->company->id, 'division_id' => ($division ?? $this->jchicken)->id, 'full_name' => $name, 'status' => 'active']);
    }

    private function scheduled(string $name, Shift $shift, string $date = '2026-11-17'): Freelancer
    {
        $worker = $this->freelancer($name);
        $this->scheduleOn($worker, $shift, $date);

        return $worker;
    }

    private function scheduleOn(Freelancer $worker, Shift $shift, string $date): void
    {
        ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $shift->id, 'company_location_id' => $this->outlet->id]);
    }

    private function attend(
        Freelancer $worker,
        string $date,
        string $checkIn,
        ?string $checkOut = null,
        string $status = 'on_time',
        int $lateMinutes = 0,
        ?Shift $shift = null,
        int $segment = 1,
        ?CompanyLocation $location = null,
    ): Attendance {
        return Attendance::create([
            'freelancer_id' => $worker->id,
            'date' => $date,
            'segment' => $segment,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'shift_id' => $shift?->id,
            'company_location_id' => ($location ?? $this->outlet)->id,
        ]);
    }
}
