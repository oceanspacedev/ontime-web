<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Freelancer;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\TeamRecapService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Month recaps, the attendance report, the account list and the team grids of a large Klien: the
 * number of queries stays the same however many rows there are, and the response shape does not change.
 */
class DailyPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Division $jchicken;

    private Division $luuca;

    private CompanyLocation $jchickenOutlet;

    private CompanyLocation $luucaOutlet;

    private Shift $morning;

    private User $admin;

    private int $workerCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 12:00'));

        foreach (['Superadmin', 'Admin', 'PIC', 'Koordinator', 'Approver', 'BOD', 'Employee', 'Intern', 'Freelancer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->company = Company::create(['name' => 'PT Franchise', 'code' => 'PFC', 'is_active' => true]);
        $this->jchicken = Division::create(['company_id' => $this->company->id, 'name' => 'JCHICKEN', 'is_active' => true]);
        $this->luuca = Division::create(['company_id' => $this->company->id, 'name' => 'LUUCA', 'is_active' => true]);
        $this->jchickenOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'JChicken Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->luucaOutlet = CompanyLocation::create(['company_id' => $this->company->id, 'division_id' => $this->luuca->id, 'name' => 'Luuca Ciledug', 'latitude' => -6.2, 'longitude' => 106.7]);
        $this->morning = Shift::create(['company_id' => $this->company->id, 'division_id' => $this->jchicken->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->admin->grantDataAccess(DataScope::All);
    }

    // ── Team recap (C5) ────────────────────────────────────────────────────

    public function test_team_recap_query_count_does_not_grow_with_the_number_of_workers(): void
    {
        $this->seedMonth(2);
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/team/recap?month=2026-11')->assertOk()->assertJsonCount(2, 'data'));

        $this->seedMonth(10);
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/team/recap?month=2026-11')->assertOk()->assertJsonCount(12, 'data'));

        $this->assertSame($few, $many, 'The recap reads plain rows in bulk, never per worker or per day.');
        $this->assertLessThanOrEqual(25, $many);
    }

    public function test_team_recap_and_its_export_never_hydrate_a_month_of_schedules_or_attendances(): void
    {
        $this->seedMonth(6);
        $hydrated = $this->countHydrated([Attendance::class, ShiftSchedule::class]);

        $this->assertCount(6, app(TeamRecapService::class)->recap($this->admin, Carbon::parse('2026-11-01')));
        $path = app(TeamRecapService::class)->export($this->admin, Carbon::parse('2026-11-01'));
        @unlink($path);

        $this->assertSame(0, $hydrated(), '60 rows read as plain records, not models with their relations.');
    }

    public function test_team_recap_keeps_its_row_shape_and_counts(): void
    {
        $bayu = $this->freelancer('Bayu Adillah', $this->jchicken);
        $this->schedule($bayu, '2026-11-02', $this->jchickenOutlet);
        $this->schedule($bayu, '2026-11-03', $this->jchickenOutlet);
        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-04', 'is_day_off' => true]);
        // A split shift: two late segments count as one late day, their work time adds up.
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'segment' => 1, 'check_in' => '2026-11-02 08:10:00', 'check_out' => '2026-11-02 12:00:00', 'status' => 'late', 'late_minutes' => 10, 'company_location_id' => $this->jchickenOutlet->id]);
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'segment' => 2, 'check_in' => '2026-11-02 13:05:00', 'check_out' => '2026-11-02 17:05:00', 'status' => 'late', 'late_minutes' => 5, 'company_location_id' => $this->jchickenOutlet->id]);
        // Last day of the month is still part of the month.
        $this->schedule($bayu, '2026-11-30', $this->jchickenOutlet);

        $rows = app(TeamRecapService::class)->recap($this->admin, Carbon::parse('2026-11-01'));

        $this->assertCount(2, $rows);
        [$outlet, $dayOff] = $rows;
        $this->assertSame(['worker', 'outlet', 'scheduled_days', 'present_days', 'late_days', 'late_minutes', 'absent_days', 'off_days', 'scheduled_hours', 'worked_hours'], array_keys($outlet));
        $this->assertSame(['type' => 'freelancer', 'id' => $bayu->id, 'number' => (string) $bayu->freelancer_number, 'name' => 'Bayu Adillah', 'company' => 'PT Franchise', 'division' => 'JCHICKEN'], $outlet['worker']);
        $this->assertSame(
            ['outlet' => 'JChicken Ciledug', 'scheduled_days' => 3, 'present_days' => 1, 'late_days' => 1, 'late_minutes' => 15, 'absent_days' => 1, 'off_days' => 0, 'scheduled_hours' => 27.0, 'worked_hours' => 7.83],
            array_diff_key($outlet, ['worker' => true]),
        );
        $this->assertSame([null, 1], [$dayOff['outlet'], $dayOff['off_days']]);
    }

    public function test_team_recap_falls_back_to_the_attendance_outlet_when_the_schedule_has_none(): void
    {
        $bayu = $this->freelancer('Bayu Adillah', $this->jchicken);
        ShiftSchedule::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'shift_id' => $this->morning->id]);
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'check_out' => '2026-11-02 17:00:00', 'status' => 'on_time', 'late_minutes' => 0, 'company_location_id' => $this->luucaOutlet->id]);

        $rows = app(TeamRecapService::class)->recap($this->admin, Carbon::parse('2026-11-01'));

        $this->assertSame([['Luuca Ciledug', 1, 1, 9.0]], array_map(fn (array $row): array => [$row['outlet'], $row['scheduled_days'], $row['present_days'], $row['worked_hours']], $rows));
    }

    public function test_team_recap_leaves_out_workers_outside_the_data_access(): void
    {
        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id]);
        $inside = $this->freelancer('Di Dalam', $this->jchicken);
        $outside = $this->freelancer('Di Luar', $this->luuca);
        Attendance::create(['freelancer_id' => $inside->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);
        Attendance::create(['freelancer_id' => $outside->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);

        $names = array_column(array_column(app(TeamRecapService::class)->recap($coordinator, Carbon::parse('2026-11-01')), 'worker'), 'name');

        $this->assertSame(['Di Dalam'], $names);
    }

    // ── Laporan absensi (C13) ──────────────────────────────────────────────

    public function test_attendance_report_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $url = '/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-14';

        $this->seedMonth(2);
        $few = $this->countQueries(fn () => $this->getJson($url)->assertOk());

        $this->seedMonth(15);
        $many = $this->countQueries(fn () => $this->getJson($url)->assertOk());

        $this->assertSame($few, $many, 'The summary is one aggregate query, not a scan of every row.');
        $this->assertLessThanOrEqual(30, $many);
    }

    public function test_attendance_report_only_hydrates_the_page_it_returns(): void
    {
        $this->seedMonth(10);
        Sanctum::actingAs($this->admin);
        $hydrated = $this->countHydrated([Attendance::class]);

        $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-01&end_date=2026-11-14')
            ->assertOk()
            ->assertJsonPath('pagination.total', 50)
            ->assertJsonPath('summary.total_present', 50)
            ->assertJsonPath('summary.total_late', 50)
            ->assertJsonCount(30, 'data');

        $this->assertSame(30, $hydrated(), 'The summary counts in the database; only the 30 rows of the page become models.');
    }

    public function test_attendance_report_summary_counts_worker_days_and_keeps_its_shape(): void
    {
        $bayu = $this->freelancer('Bayu Adillah', $this->jchicken);
        $sari = $this->freelancer('Sari', $this->luuca);
        // Monday: on time. Tuesday: a split shift late on both segments (one late day). Wednesday: absent.
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time', 'late_minutes' => 0]);
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-03', 'segment' => 1, 'check_in' => '2026-11-03 08:10:00', 'status' => 'late', 'late_minutes' => 10]);
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-03', 'segment' => 2, 'check_in' => '2026-11-03 13:10:00', 'status' => 'late', 'late_minutes' => 10]);
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-04', 'status' => 'absent', 'late_minutes' => 0]);
        Attendance::create(['freelancer_id' => $sari->id, 'date' => '2026-11-04', 'check_in' => '2026-11-04 08:00:00', 'status' => 'rejected', 'late_minutes' => 0]);
        // Thursday is on approved leave, Friday has nothing: one unrecorded weekday.
        LeaveRequest::create(['freelancer_id' => $sari->id, 'leave_type' => 'annual', 'start_date' => '2026-11-05', 'end_date' => '2026-11-05', 'days_count' => 1, 'reason' => 'Acara keluarga', 'status' => 'approved']);
        // Outside the range.
        Attendance::create(['freelancer_id' => $bayu->id, 'date' => '2026-11-09', 'check_in' => '2026-11-09 08:00:00', 'status' => 'on_time']);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-02&end_date=2026-11-06')->assertOk();

        $response->assertJsonStructure(['start_date', 'end_date', 'summary' => ['total_present', 'total_late', 'total_leave', 'total_absent'], 'data', 'pagination' => ['current_page', 'last_page', 'per_page', 'total']]);
        // Present: Bayu Mon + Tue, Sari Wed (checked in, then rejected). Absent: 2 explicit rows + Friday.
        $this->assertSame(['total_present' => 3, 'total_late' => 1, 'total_leave' => 1, 'total_absent' => 3], $response->json('summary'));
        $this->assertSame(5, $response->json('pagination.total'));

        $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-02&end_date=2026-11-06&company_id='.($this->company->id + 999))
            ->assertOk()
            ->assertJsonPath('summary.total_present', 0)
            ->assertJsonPath('pagination.total', 0);
    }

    public function test_attendance_report_stays_inside_the_data_access(): void
    {
        $coordinator = User::factory()->create();
        $coordinator->assignRole('Koordinator');
        $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id]);
        $inside = $this->freelancer('Di Dalam', $this->jchicken);
        $outside = $this->freelancer('Di Luar', $this->luuca);
        Attendance::create(['freelancer_id' => $inside->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);
        Attendance::create(['freelancer_id' => $outside->id, 'date' => '2026-11-02', 'check_in' => '2026-11-02 08:00:00', 'status' => 'on_time']);
        Attendance::create(['freelancer_id' => $outside->id, 'date' => '2026-11-03', 'check_in' => '2026-11-03 08:00:00', 'status' => 'on_time']);

        Sanctum::actingAs($coordinator);

        $this->getJson('/api/v1/laporan-absensi?start_date=2026-11-02&end_date=2026-11-03')
            ->assertOk()
            ->assertJsonPath('summary.total_present', 1)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.freelancer_id', $inside->id);
    }

    // ── Akun & akses (C15) ─────────────────────────────────────────────────

    public function test_account_list_query_count_does_not_grow_with_the_number_of_accounts(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA']);
        $this->company->forceFill(['tenant_id' => $tenant->id])->save();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->forceFill(['tenant_id' => $tenant->id])->save();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::All);

        $this->seedAccounts($tenant, 2);
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/client/accounts')->assertOk()->assertJsonCount(6, 'data'), $admin);

        $this->seedAccounts($tenant, 8);
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/client/accounts')->assertOk()->assertJsonCount(30, 'data'), $admin);

        $this->assertSame($few, $many, 'Roles, data access and workers come eager loaded; the rules are resolved once per request.');
        $this->assertLessThanOrEqual(40, $many);

        Sanctum::actingAs(User::query()->find($admin->id));
        $account = $this->getJson('/api/v1/client/accounts')->assertOk()->json('data.0');
        $this->assertSame(['id', 'name', 'email', 'phone', 'is_active', 'roles', 'data_scope', 'data_scope_label', 'companies', 'divisions', 'worker', 'can_edit'], array_keys($account));
        $this->assertTrue($account['can_edit']);
    }

    public function test_account_list_still_hides_accounts_wider_than_a_division_admin(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Klien A', 'code' => 'KA']);
        $this->company->forceFill(['tenant_id' => $tenant->id])->save();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->forceFill(['tenant_id' => $tenant->id])->save();
        $admin->assignRole('Admin');
        $admin->grantDataAccess(DataScope::Divisions, divisionIds: [$this->jchicken->id]);

        $own = $this->linkedAccount($tenant, 'Approver Jchicken', $this->jchicken, DataScope::Divisions, divisionIds: [$this->jchicken->id]);
        $wider = $this->linkedAccount($tenant, 'Approver Luuca', $this->jchicken, DataScope::Divisions, divisionIds: [$this->luuca->id]);
        $company = $this->linkedAccount($tenant, 'Approver Badan Usaha', $this->jchicken, DataScope::Companies, companyIds: [$this->company->id]);

        Sanctum::actingAs($admin->fresh());
        $ids = array_column($this->getJson('/api/v1/client/accounts')->assertOk()->json('data'), 'id');

        $this->assertContains($own->id, $ids);
        $this->assertNotContains($wider->id, $ids);
        $this->assertNotContains($company->id, $ids);
    }

    // ── Roster and week (C14) ──────────────────────────────────────────────

    public function test_roster_and_week_can_be_narrowed_to_one_outlet_brand_or_company(): void
    {
        $bayu = $this->freelancer('Bayu Adillah', $this->jchicken);
        $sari = $this->freelancer('Sari', $this->luuca);
        $date = '2026-11-16';
        $this->schedule($bayu, $date, $this->jchickenOutlet);
        $this->schedule($sari, $date, $this->luucaOutlet);
        Sanctum::actingAs($this->admin);

        $outlets = fn (string $query): array => array_column(array_column($this->getJson("/api/v1/team/roster?date={$date}{$query}")->assertOk()->json('outlets'), 'location'), 'name');
        $workers = fn (string $query): array => array_column(array_column($this->getJson("/api/v1/team/week?date={$date}{$query}")->assertOk()->json('workers'), 'worker'), 'name');

        $this->assertSame(['JChicken Ciledug', 'Luuca Ciledug'], $outlets(''));
        $this->assertSame(['Luuca Ciledug'], $outlets("&location_id={$this->luucaOutlet->id}"));
        $this->assertSame(['JChicken Ciledug'], $outlets("&division_id={$this->jchicken->id}"));
        $this->assertSame(['JChicken Ciledug', 'Luuca Ciledug'], $outlets("&company_id={$this->company->id}"));
        $this->assertSame([], $outlets('&company_id='.($this->company->id + 999)));

        $this->assertSame(['Bayu Adillah', 'Sari'], $workers(''));
        $this->assertSame(['Sari'], $workers("&location_id={$this->luucaOutlet->id}"));
        $this->assertSame(['Bayu Adillah'], $workers("&division_id={$this->jchicken->id}"));

        $this->getJson('/api/v1/team/week?location_id=abc')->assertUnprocessable();
    }

    public function test_roster_and_week_keep_the_shape_the_app_reads(): void
    {
        $bayu = $this->freelancer('Bayu Adillah', $this->jchicken);
        $this->schedule($bayu, '2026-11-15', $this->jchickenOutlet);
        Sanctum::actingAs($this->admin);

        $roster = $this->getJson('/api/v1/team/roster?date=2026-11-15')->assertOk()->json();
        $this->assertSame(['date', 'outlets'], array_keys($roster));
        $this->assertSame(['location', 'counts', 'entries'], array_keys($roster['outlets'][0]));
        $this->assertSame(['schedule_id', 'worker', 'shift', 'location_id', 'borrowed_from', 'section', 'state', 'check_in', 'check_out', 'late_minutes', 'segments', 'attendances'], array_keys($roster['outlets'][0]['entries'][0]));

        // The 15th is a Sunday, the last day of its week.
        $week = $this->getJson('/api/v1/team/week?date=2026-11-15')->assertOk()->json();
        $this->assertSame(['start', 'end', 'days', 'workers'], array_keys($week));
        $this->assertSame(['2026-11-09', '2026-11-15'], [$week['start'], $week['end']]);
        $this->assertSame(['schedule_id', 'is_day_off', 'shift', 'location_id', 'outlet', 'section', 'borrowed_from'], array_keys($week['workers'][0]['days']['2026-11-15']));
    }

    public function test_week_query_count_does_not_grow_with_the_number_of_workers(): void
    {
        $this->seedWeek(2);
        $few = $this->countQueries(fn () => $this->getJson('/api/v1/team/week?date=2026-11-16')->assertOk()->assertJsonCount(2, 'workers'));

        $this->seedWeek(10);
        $many = $this->countQueries(fn () => $this->getJson('/api/v1/team/week?date=2026-11-16')->assertOk()->assertJsonCount(12, 'workers'));

        $this->assertSame($few, $many);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Queries run by the request, signed in as a fresh copy of the user so nothing is cached from earlier.
     */
    private function countQueries(Closure $request, ?User $user = null): int
    {
        DataAccessService::flush();
        Sanctum::actingAs(User::query()->find(($user ?? $this->admin)->id));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Count the models of the given classes loaded from here on.
     *
     * @param  list<class-string>  $models
     * @return Closure(): int
     */
    private function countHydrated(array $models): Closure
    {
        $count = 0;

        foreach ($models as $model) {
            Event::listen("eloquent.retrieved: {$model}", function () use (&$count): void {
                $count++;
            });
        }

        return function () use (&$count): int {
            return $count;
        };
    }

    private function freelancer(string $name, Division $division): Freelancer
    {
        return Freelancer::create(['company_id' => $this->company->id, 'division_id' => $division->id, 'full_name' => $name, 'status' => 'active']);
    }

    private function schedule(Freelancer $worker, string $date, CompanyLocation $outlet): ShiftSchedule
    {
        return ShiftSchedule::create(['freelancer_id' => $worker->id, 'date' => $date, 'shift_id' => $this->morning->id, 'company_location_id' => $outlet->id]);
    }

    /**
     * Workers with five scheduled days in early November, each with an attendance.
     */
    private function seedMonth(int $workers): void
    {
        foreach (range(1, $workers) as $ignored) {
            $worker = $this->freelancer('Pekerja '.str_pad((string) ++$this->workerCount, 3, '0', STR_PAD_LEFT), $this->workerCount % 2 ? $this->jchicken : $this->luuca);

            foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $day) {
                $this->schedule($worker, $day, $this->jchickenOutlet);
                Attendance::create(['freelancer_id' => $worker->id, 'date' => $day, 'check_in' => "{$day} 08:05:00", 'check_out' => "{$day} 17:00:00", 'status' => 'late', 'late_minutes' => 5, 'company_location_id' => $this->jchickenOutlet->id]);
            }
        }
    }

    private function seedWeek(int $workers): void
    {
        foreach (range(1, $workers) as $ignored) {
            $worker = $this->freelancer('Pekerja '.str_pad((string) ++$this->workerCount, 3, '0', STR_PAD_LEFT), $this->workerCount % 2 ? $this->jchicken : $this->luuca);

            foreach (['2026-11-16', '2026-11-17', '2026-11-18'] as $day) {
                $this->schedule($worker, $day, $this->workerCount % 2 ? $this->jchickenOutlet : $this->luucaOutlet);
            }
        }
    }

    /**
     * Three linked accounts per round: one each with data access "Divisi", "Badan Usaha" and "Pribadi".
     */
    private function seedAccounts(Tenant $tenant, int $rounds): void
    {
        foreach (range(1, $rounds) as $ignored) {
            $this->linkedAccount($tenant, 'Akun '.++$this->workerCount.'a', $this->jchicken, DataScope::Divisions, divisionIds: [$this->jchicken->id]);
            $this->linkedAccount($tenant, 'Akun '.$this->workerCount.'b', $this->luuca, DataScope::Companies, companyIds: [$this->company->id]);
            $this->linkedAccount($tenant, 'Akun '.$this->workerCount.'c', $this->luuca, DataScope::Own, role: 'Freelancer');
        }
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $divisionIds
     */
    private function linkedAccount(Tenant $tenant, string $name, Division $division, DataScope $scope, array $companyIds = [], array $divisionIds = [], string $role = 'Approver'): User
    {
        $account = User::factory()->create(['name' => $name, 'is_active' => true]);
        $account->forceFill(['tenant_id' => $tenant->id])->save();
        $account->assignRole($role);
        $account->grantDataAccess($scope, $companyIds, $divisionIds);
        Freelancer::create(['company_id' => $this->company->id, 'division_id' => $division->id, 'user_id' => $account->id, 'full_name' => $name, 'status' => 'active']);

        return $account;
    }
}
