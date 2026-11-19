<?php

namespace Database\Seeders;

use App\Enums\AttendanceStatus;
use App\Enums\DataScope;
use App\Models\ApprovalFlow;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\JobLevel;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalFlowService;
use Carbon\CarbonImmutable;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * A large two-client dataset for QA and stress tests of daily operations (SaaS tenancy):
 *
 * - Klien QA (code QA): PIC, Admin HR, 2 Koordinator, 1 Approver per company, 1 BOD and three companies
 *   with different attendance policies (A: klik saja, B: GPS + geofence, C: GPS + foto), ~1200
 *   employees, ~150 interns and ~150 freelancers, shifts (one split shift), this week's schedules,
 *   yesterday's attendance and some pending leave, overtime and correction requests.
 * - Klien B (code QB): one company, a PIC, an Approver and ~50 workers, for isolation checks.
 * - A Superadmin outside every Klien, who only monitors.
 *
 * Every account uses the password in self::PASSWORD. Safe to run again: records are matched by their
 * unique keys and the pending requests are only made once.
 * Run with: php artisan db:seed --class=QaDailyOpsSeeder
 */
class QaDailyOpsSeeder extends Seeder
{
    public const PASSWORD = 'QaOntime2026';

    private const REQUEST_MARKER = '[QA]';

    /**
     * Company key => definition. Workers counts are per type: [employees, interns, freelancers].
     *
     * @var array<string, array{tenant: string, code: string, name: string, mode: string, policy: array<string, mixed>, divisions: array<string, list<string>>, workers: array{int, int, int}}>
     */
    private const COMPANIES = [
        'A' => [
            'tenant' => 'QA',
            'code' => 'QAA',
            'name' => 'PT QA Klik Retail',
            'mode' => 'klik',
            'policy' => ['require_gps' => false, 'require_photo' => false],
            'divisions' => [
                'HEAD OFFICE' => ['HRGA', 'FINANCE'],
                'STORE' => ['TOKO SUDIRMAN', 'TOKO KEMANG', 'TOKO DEPOK'],
            ],
            'workers' => [350, 50, 30],
        ],
        'B' => [
            'tenant' => 'QA',
            'code' => 'QAB',
            'name' => 'PT QA GPS Distribusi',
            'mode' => 'gps',
            'policy' => [
                'require_gps' => true,
                'require_photo' => false,
                'geofence_latitude' => -6.2000000,
                'geofence_longitude' => 106.8166000,
                'geofence_radius_meters' => 150,
            ],
            'divisions' => [
                'DISTRIBUTION SALES' => ['DEPO JAKARTA', 'DEPO BANDUNG', 'DEPO BEKASI'],
                'WAREHOUSE' => ['GUDANG CAKUNG'],
            ],
            'workers' => [450, 50, 40],
        ],
        'C' => [
            'tenant' => 'QA',
            'code' => 'QAC',
            'name' => 'PT QA Foto Kuliner',
            'mode' => 'gps_foto',
            // Outlets on shift rosters: a day without a schedule is not scheduled (D3).
            'policy' => ['require_gps' => true, 'require_photo' => true, 'geofence_radius_meters' => 150, 'uses_shift_roster' => true],
            'divisions' => [
                'KOPI NUSA' => ['OUTLET KOPI CILEDUG', 'OUTLET KOPI BINTARO'],
                'AYAM GEPREK' => ['OUTLET AYAM CILEDUG'],
                'MIE PEDAS' => ['OUTLET MIE CILEDUG'],
                'OFFICE KULINER' => ['KANTOR CILEDUG'],
            ],
            'workers' => [400, 50, 80],
        ],
        'KB' => [
            'tenant' => 'QB',
            'code' => 'KBL',
            'name' => 'PT Klien B Logistik',
            'mode' => 'klik',
            'policy' => ['require_gps' => false, 'require_photo' => false],
            'divisions' => [
                'OPERASIONAL' => ['ARMADA', 'ADMIN GUDANG'],
            ],
            'workers' => [40, 5, 5],
        ],
    ];

    /**
     * Leaf division => [location name, latitude, longitude, radius].
     *
     * @var array<string, array{string, float, float, int}>
     */
    private const LOCATIONS = [
        'DEPO JAKARTA' => ['Depo Jakarta', -6.2000000, 106.8166000, 150],
        'DEPO BANDUNG' => ['Depo Bandung', -6.9175000, 107.6191000, 150],
        'DEPO BEKASI' => ['Depo Bekasi', -6.2383000, 106.9756000, 150],
        'OUTLET KOPI CILEDUG' => ['Kopi Nusa Ciledug', -6.2285000, 106.7120000, 100],
        'OUTLET KOPI BINTARO' => ['Kopi Nusa Bintaro', -6.2726000, 106.7244000, 100],
        'OUTLET AYAM CILEDUG' => ['Ayam Geprek Ciledug', -6.2295000, 106.7130000, 100],
        'OUTLET MIE CILEDUG' => ['Mie Pedas Ciledug', -6.2305000, 106.7140000, 100],
        'KANTOR CILEDUG' => ['Kantor Kuliner Ciledug', -6.2250000, 106.7100000, 150],
    ];

    /**
     * Company key => [division name or null for company-wide, name, code, start, end, second start, second end].
     *
     * @var array<string, list<array{string|null, string, string, string, string, string|null, string|null}>>
     */
    private const SHIFTS = [
        'A' => [
            [null, 'Reguler', 'R', '08:00', '17:00', null, null],
            ['STORE', 'Toko Pagi', 'P', '07:00', '15:00', null, null],
            ['STORE', 'Toko Siang', 'S', '14:00', '22:00', null, null],
        ],
        'B' => [
            [null, 'Reguler', 'R', '08:00', '17:00', null, null],
            ['DISTRIBUTION SALES', 'Depo Pagi', 'P', '06:00', '14:00', null, null],
            ['DISTRIBUTION SALES', 'Depo Siang', 'S', '14:00', '22:00', null, null],
        ],
        'C' => [
            [null, 'Reguler', 'R', '08:00', '17:00', null, null],
            ['KOPI NUSA', 'Pagi', 'P', '08:00', '17:00', null, null],
            ['KOPI NUSA', 'Split Kopi', 'SP', '07:00', '11:00', '16:00', '20:00'],
            ['AYAM GEPREK', 'Pagi', 'P', '09:00', '18:00', null, null],
            ['AYAM GEPREK', 'Siang', 'S', '13:00', '22:00', null, null],
            ['MIE PEDAS', 'Pagi', 'P', '10:00', '19:00', null, null],
            ['MIE PEDAS', 'Siang', 'S', '13:00', '22:00', null, null],
        ],
        'KB' => [
            [null, 'Reguler', 'R', '08:00', '17:00', null, null],
        ],
    ];

    private Generator $faker;

    private string $passwordHash;

    private CarbonImmutable $today;

    /** @var array<string, Tenant> */
    private array $tenants = [];

    /** @var array<string, Company> */
    private array $companies = [];

    /** @var array<string, array<string, Division>> company key => division name => division */
    private array $divisions = [];

    /** @var array<string, array<string, CompanyLocation>> company key => leaf division name => location */
    private array $locations = [];

    /** @var array<string, list<Shift>> company key => shifts */
    private array $shifts = [];

    /** @var array<string, int> */
    private array $roleIds = [];

    /** @var array<string, int> */
    private array $phoneCounters = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('QaDailyOpsSeeder creates accounts with a known password and must not run in production.');
        }

        $startedAt = microtime(true);
        $this->faker = fake('id_ID');
        $this->faker->seed(20261115);
        $this->passwordHash = Hash::make(self::PASSWORD);
        $this->today = CarbonImmutable::today();

        $this->call(RoleSeeder::class);
        $this->roleIds = Role::query()->where('guard_name', 'web')->pluck('id', 'name')->all();

        $this->seedTenants();
        $this->seedCompanies();
        $this->seedApprovalFlows();
        $this->seedManagementAccounts();

        foreach (array_keys(self::COMPANIES) as $companyKey) {
            $this->seedWorkers($companyKey);
        }

        $this->seedSchedules();
        $this->seedYesterdayAttendance();
        $this->seedPendingRequests();

        $this->printCounts(microtime(true) - $startedAt);
    }

    private function seedTenants(): void
    {
        $this->tenants['QA'] = Tenant::query()->updateOrCreate(['code' => 'QA'], [
            'name' => 'Klien QA',
            'contact_name' => 'PIC Klien QA',
            'contact_email' => 'qa.pic@ontime.test',
            'contact_phone' => '6281000000001',
            'is_active' => true,
        ]);

        $this->tenants['QB'] = Tenant::query()->updateOrCreate(['code' => 'QB'], [
            'name' => 'Klien B',
            'contact_name' => 'PIC Klien B',
            'contact_email' => 'kb.pic@ontime.test',
            'contact_phone' => '6281000000101',
            'is_active' => true,
        ]);
    }

    private function seedCompanies(): void
    {
        JobLevel::query()->firstOrCreate(['name' => 'STAFF'], ['level_order' => 1]);
        JobLevel::query()->firstOrCreate(['name' => 'SUPERVISOR'], ['level_order' => 2]);
        JobLevel::query()->firstOrCreate(['name' => 'MANAGER'], ['level_order' => 3]);

        foreach (self::COMPANIES as $key => $definition) {
            $tenant = $this->tenants[$definition['tenant']];
            $company = Company::query()->firstOrNew(['tenant_id' => $tenant->id, 'code' => $definition['code']]);
            $company->forceFill(['tenant_id' => $tenant->id, 'name' => $definition['name'], 'is_active' => true])->save();
            $company->policy()->update(array_merge([
                'late_tolerance_minutes' => 15,
                'require_face_recognition' => false,
                'require_liveness' => false,
                'geofence_latitude' => null,
                'geofence_longitude' => null,
                'geofence_radius_meters' => 100,
                'annual_leave_quota' => 12,
                'work_start_time' => '08:00:00',
                'work_end_time' => '17:00:00',
                'uses_shift_roster' => false,
            ], $definition['policy']));
            $this->companies[$key] = $company;

            foreach ($definition['divisions'] as $parentName => $children) {
                $parent = Division::query()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $parentName, 'parent_id' => null],
                    ['is_active' => true],
                );
                $this->divisions[$key][$parentName] = $parent;

                foreach ($children as $childName) {
                    $child = Division::query()->firstOrCreate(
                        ['company_id' => $company->id, 'name' => $childName, 'parent_id' => $parent->id],
                        ['is_active' => true],
                    );
                    $this->divisions[$key][$childName] = $child;

                    if (isset(self::LOCATIONS[$childName])) {
                        [$locationName, $latitude, $longitude, $radius] = self::LOCATIONS[$childName];
                        $this->locations[$key][$childName] = CompanyLocation::query()->updateOrCreate(
                            ['company_id' => $company->id, 'name' => $locationName],
                            ['division_id' => $child->id, 'latitude' => $latitude, 'longitude' => $longitude, 'radius_meters' => $radius, 'is_active' => true],
                        );
                    }
                }
            }

            foreach (self::SHIFTS[$key] as [$divisionName, $name, $code, $start, $end, $secondStart, $secondEnd]) {
                $this->shifts[$key][] = Shift::query()->updateOrCreate(
                    ['company_id' => $company->id, 'division_id' => $divisionName ? $this->divisions[$key][$divisionName]->id : null, 'name' => $name],
                    ['code' => $code, 'start_time' => $start, 'end_time' => $end, 'second_start_time' => $secondStart, 'second_end_time' => $secondEnd, 'is_active' => true],
                );
            }
        }

        JobTitle::query()->firstOrCreate(['name' => 'STAFF OPERASIONAL', 'division_id' => null]);
        JobTitle::query()->firstOrCreate(['name' => 'KOORDINATOR OUTLET', 'division_id' => null]);
    }

    /**
     * Every company gets the default one-stage flow (Approver); company B uses Approver then BOD.
     */
    private function seedApprovalFlows(): void
    {
        $service = app(ApprovalFlowService::class);

        foreach ($this->companies as $key => $company) {
            if ($key !== 'B') {
                $service->createDefaultFlows($company);

                continue;
            }

            ApprovalFlow::query()->where('company_id', $company->id)->whereNull('division_id')->delete();

            foreach (ApprovalFlowService::REQUEST_TYPE_LABELS as $requestType => $label) {
                foreach ([1 => 'Approver', 2 => 'BOD'] as $order => $role) {
                    ApprovalFlow::query()->create([
                        'company_id' => $company->id,
                        'request_type' => $requestType,
                        'step_number' => $order,
                        'step_order' => $order,
                        'name' => "Persetujuan {$label} ({$role})",
                        'approver_type' => 'role',
                        'approver_role' => $role,
                    ]);
                }
            }
        }
    }

    private function seedManagementAccounts(): void
    {
        $qa = $this->tenants['QA'];
        $kb = $this->tenants['QB'];

        $this->account('qa.superadmin@ontime.test', 'QA Superadmin', ['Superadmin'], null)->grantDataAccess(DataScope::All);
        $this->account('qa.pic@ontime.test', 'QA PIC Klien', ['Admin', 'PIC'], $qa)->grantDataAccess(DataScope::All);
        $this->account('qa.admin.hr@ontime.test', 'QA Admin HR', ['Admin'], $qa)->grantDataAccess(DataScope::All);
        $this->account('qa.bod@ontime.test', 'QA BOD Direktur', ['BOD'], $qa)->grantDataAccess(DataScope::All);

        foreach (['A', 'B', 'C'] as $key) {
            $this->account('qa.approver.'.strtolower($key).'@ontime.test', "QA Approver {$key}", ['Approver'], $qa)
                ->grantDataAccess(DataScope::Companies, companyIds: [$this->companies[$key]->id]);
        }

        $coordinators = [
            ['qa.koord.kopi@ontime.test', 'QA Koordinator Kopi & Ayam', ['KOPI NUSA', 'AYAM GEPREK'], 'OUTLET KOPI CILEDUG', 'QAC-KOORD-01'],
            ['qa.koord.mie@ontime.test', 'QA Koordinator Mie', ['MIE PEDAS'], 'OUTLET MIE CILEDUG', 'QAC-KOORD-02'],
        ];

        foreach ($coordinators as [$email, $name, $brands, $homeDivision, $nip]) {
            $coordinator = $this->account($email, $name, ['Koordinator', 'Employee'], $qa);
            $coordinator->grantDataAccess(DataScope::Divisions, divisionIds: array_map(fn (string $brand): int => $this->divisions['C'][$brand]->id, $brands));

            Employee::query()->updateOrCreate(['company_id' => $this->companies['C']->id, 'nip' => $nip], [
                'user_id' => $coordinator->id,
                'division_id' => $this->divisions['C'][$homeDivision]->id,
                'shift_id' => $this->shifts['C'][0]->id,
                'job_level_id' => JobLevel::query()->where('name', 'SUPERVISOR')->value('id'),
                'job_title_id' => JobTitle::query()->where('name', 'KOORDINATOR OUTLET')->value('id'),
                'full_name' => $name,
                'phone' => $coordinator->phone,
                'join_date' => $this->today->subYear()->toDateString(),
                'status' => 'active',
            ]);
        }

        $this->account('kb.pic@ontime.test', 'KB PIC Klien', ['Admin', 'PIC'], $kb)->grantDataAccess(DataScope::All);
        $this->account('kb.approver@ontime.test', 'KB Approver', ['Approver'], $kb)
            ->grantDataAccess(DataScope::Companies, companyIds: [$this->companies['KB']->id]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function account(string $email, string $name, array $roles, ?Tenant $tenant): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'phone' => $user->phone ?: $this->nextPhone('0'),
            'password' => $this->passwordHash,
            'is_active' => true,
            'tenant_id' => $tenant?->id,
        ])->save();
        $user->syncRoles($roles);

        return $user;
    }

    private function nextPhone(string $series): string
    {
        $this->phoneCounters[$series] = ($this->phoneCounters[$series] ?? 0) + 1;

        return '6281'.$series.str_pad((string) $this->phoneCounters[$series], 8, '0', STR_PAD_LEFT);
    }

    /**
     * Workers of one company, spread over its leaf divisions, each with an active account.
     */
    private function seedWorkers(string $companyKey): void
    {
        $definition = self::COMPANIES[$companyKey];
        $company = $this->companies[$companyKey];
        $tenantId = $this->tenants[$definition['tenant']]->id;
        $prefix = $companyKey === 'KB' ? 'kb' : 'qa.'.strtolower($companyKey);
        $series = $companyKey === 'KB' ? '9' : '1';
        $leafDivisions = $this->leafDivisions($companyKey);
        $defaultShiftId = $this->shifts[$companyKey][0]->id;
        $staffLevelId = JobLevel::query()->where('name', 'STAFF')->value('id');
        $jobTitleId = JobTitle::query()->where('name', 'STAFF OPERASIONAL')->value('id');
        $now = now();
        [$employeeCount, $internCount, $freelancerCount] = $definition['workers'];

        $types = [
            'emp' => ['role' => 'Employee', 'count' => $employeeCount],
            'int' => ['role' => 'Intern', 'count' => $internCount],
            'fl' => ['role' => 'Freelancer', 'count' => $freelancerCount],
        ];

        foreach ($types as $type => ['role' => $role, 'count' => $count]) {
            $people = [];

            for ($i = 1; $i <= $count; $i++) {
                $number = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                $phoneSeries = $series.['emp' => '1', 'int' => '2', 'fl' => '3'][$type].($companyKey === 'KB' ? '9' : (string) (ord($companyKey) - 64));
                $people[] = [
                    'email' => "{$prefix}.{$type}{$number}@ontime.test",
                    'name' => $this->faker->name(),
                    'phone' => '6281'.$phoneSeries.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                    'number' => strtoupper($definition['code']).'-'.strtoupper($type).'-'.$number,
                    'division_id' => $leafDivisions[($i - 1) % count($leafDivisions)]->id,
                    'index' => $i,
                ];
            }

            foreach (array_chunk($people, 500) as $chunk) {
                DB::table('users')->upsert(array_map(fn (array $person): array => [
                    'tenant_id' => $tenantId,
                    'name' => $person['name'],
                    'email' => $person['email'],
                    'phone' => $person['phone'],
                    'is_active' => true,
                    'data_scope' => DataScope::Own->value,
                    'password' => $this->passwordHash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk), ['email'], ['tenant_id', 'name', 'phone', 'is_active', 'data_scope', 'password', 'updated_at']);

                $userIds = DB::table('users')->whereIn('email', array_column($chunk, 'email'))->pluck('id', 'email');

                DB::table('model_has_roles')->insertOrIgnore(array_map(fn (int $userId): array => [
                    'role_id' => $this->roleIds[$role],
                    'model_type' => (new User)->getMorphClass(),
                    'model_id' => $userId,
                ], $userIds->values()->all()));

                $profiles = [];

                foreach ($chunk as $person) {
                    $base = [
                        'user_id' => $userIds[$person['email']],
                        'company_id' => $company->id,
                        'division_id' => $person['division_id'],
                        'shift_id' => $defaultShiftId,
                        'full_name' => $person['name'],
                        'email' => $person['email'],
                        'phone' => $person['phone'],
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $profiles[] = match ($type) {
                        'emp' => $base + [
                            'nip' => $person['number'],
                            'job_level_id' => $staffLevelId,
                            'job_title_id' => $jobTitleId,
                            'gender' => $person['index'] % 2 === 0 ? 'female' : 'male',
                            'join_date' => $this->today->subDays(60 + $person['index'] % 900)->toDateString(),
                            'has_annual_leave' => $person['index'] % 2 === 0,
                            'annual_leave_quota' => $person['index'] % 2 === 0 ? 12 : null,
                        ],
                        'int' => $base + [
                            'nis' => $person['number'],
                            'institution' => 'SMK QA Nusantara',
                            'start_date' => $this->today->subMonths(2)->toDateString(),
                            'end_date' => $this->today->addMonths(4)->toDateString(),
                        ],
                        'fl' => $base + [
                            'freelancer_number' => $person['number'],
                            'institution' => 'Freelance',
                            'start_date' => $this->today->subMonth()->toDateString(),
                            'end_date' => $this->today->addMonths(3)->toDateString(),
                        ],
                    };
                }

                [$table, $uniqueKey] = match ($type) {
                    'emp' => ['employees', 'nip'],
                    'int' => ['interns', 'nis'],
                    'fl' => ['freelancers', 'freelancer_number'],
                };
                $updateColumns = array_values(array_diff(array_keys($profiles[0]), ['company_id', $uniqueKey, 'created_at']));
                DB::table($table)->upsert($profiles, ['company_id', $uniqueKey], $updateColumns);
            }
        }

        // Mentors and supervisors: the first employee of the same division.
        $firstEmployeeByDivision = Employee::query()
            ->where('company_id', $company->id)
            ->selectRaw('division_id, MIN(id) as id')
            ->groupBy('division_id')
            ->pluck('id', 'division_id');

        foreach ($firstEmployeeByDivision as $divisionId => $employeeId) {
            Intern::query()->where('company_id', $company->id)->where('division_id', $divisionId)->update(['mentor_id' => $employeeId]);
            Freelancer::query()->where('company_id', $company->id)->where('division_id', $divisionId)->update(['supervisor_id' => $employeeId]);
        }
    }

    /**
     * @return list<Division>
     */
    private function leafDivisions(string $companyKey): array
    {
        $leaves = [];

        foreach (self::COMPANIES[$companyKey]['divisions'] as $children) {
            foreach ($children as $childName) {
                $leaves[] = $this->divisions[$companyKey][$childName];
            }
        }

        return $leaves;
    }

    /**
     * Schedules from Monday of this week to six days ahead for about 85% of the workers, with one day
     * off in seven. Company C is scheduled at the outlet of the worker's division.
     */
    private function seedSchedules(): void
    {
        $dates = [];

        for ($date = $this->today->startOfWeek(); $date->lte($this->today->addDays(6)); $date = $date->addDay()) {
            $dates[] = $date;
        }

        $creatorId = User::query()->where('email', 'qa.admin.hr@ontime.test')->value('id');
        $now = now();

        foreach ($this->companies as $key => $company) {
            $shiftsByDivision = $this->shiftsByLeafDivision($key);

            foreach (['employee_id' => Employee::class, 'intern_id' => Intern::class, 'freelancer_id' => Freelancer::class] as $column => $model) {
                $rows = [];

                foreach ($model::query()->where('company_id', $company->id)->orderBy('id')->get(['id', 'division_id']) as $index => $worker) {
                    if ($index % 7 === 6) {
                        continue;
                    }

                    $candidates = $shiftsByDivision[$worker->division_id];
                    $location = $this->locationForDivision($key, $worker->division_id);

                    foreach ($dates as $dayIndex => $date) {
                        $isDayOff = ($index + $dayIndex) % 7 === 0;
                        $shift = $candidates[($index + $dayIndex) % count($candidates)];

                        // Company B: half of the workers are tied to their depo, the rest may use any location.
                        $locationId = match (true) {
                            $isDayOff || $location === null => null,
                            $key === 'B' && $index % 2 === 1 => null,
                            default => $location->id,
                        };

                        $rows[] = [
                            'date' => $date->toDateString(),
                            $column => $worker->id,
                            'shift_id' => $isDayOff ? null : $shift->id,
                            'company_location_id' => $locationId,
                            'is_day_off' => $isDayOff,
                            'section' => null,
                            'notes' => null,
                            'created_by' => $creatorId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($rows, 1000) as $chunk) {
                    DB::table('shift_schedules')->upsert($chunk, [$column, 'date'], ['shift_id', 'company_location_id', 'is_day_off', 'created_by', 'updated_at']);
                }
            }
        }
    }

    /**
     * @return array<int, list<Shift>> leaf division id => shifts usable there (company-wide and parent division)
     */
    private function shiftsByLeafDivision(string $companyKey): array
    {
        $map = [];

        foreach ($this->leafDivisions($companyKey) as $leaf) {
            $usable = array_values(array_filter(
                $this->shifts[$companyKey],
                fn (Shift $shift): bool => (int) $shift->division_id === (int) $leaf->parent_id,
            ));
            $map[$leaf->id] = $usable !== [] ? $usable : [$this->shifts[$companyKey][0]];
        }

        return $map;
    }

    private function locationForDivision(string $companyKey, int $divisionId): ?CompanyLocation
    {
        foreach ($this->locations[$companyKey] ?? [] as $location) {
            if ((int) $location->division_id === $divisionId) {
                return $location;
            }
        }

        return null;
    }

    /**
     * Yesterday's attendance for about 80% of the workers scheduled yesterday; about 15% of them late.
     */
    private function seedYesterdayAttendance(): void
    {
        $yesterday = $this->today->subDay();
        $now = now();
        $shifts = Shift::query()->whereIn('company_id', collect($this->companies)->pluck('id'))->get()->keyBy('id');
        $locations = CompanyLocation::query()->whereIn('company_id', collect($this->companies)->pluck('id'))->get()->keyBy('id');

        foreach (['employee_id', 'intern_id', 'freelancer_id'] as $column) {
            $schedules = DB::table('shift_schedules')
                ->whereDate('date', $yesterday->toDateString())
                ->whereNotNull($column)
                ->where('is_day_off', false)
                ->orderBy('id')
                ->get([$column, 'shift_id', 'company_location_id']);

            $rows = [];

            foreach ($schedules as $index => $schedule) {
                if ($index % 5 === 4) {
                    continue;
                }

                $shift = $shifts[$schedule->shift_id];
                $location = $schedule->company_location_id ? $locations[$schedule->company_location_id] : null;
                $segments = [1 => [$shift->start_time, $shift->end_time]];

                if ($shift->second_start_time) {
                    $segments[2] = [$shift->second_start_time, $shift->second_end_time];
                }

                foreach ($segments as $segment => [$start, $end]) {
                    $lateMinutes = $index % 7 === 3 ? 16 + $index % 30 : 0;
                    $checkIn = $yesterday->setTimeFromTimeString((string) $start)->addMinutes($lateMinutes > 0 ? $lateMinutes : -($index % 12));
                    $checkOut = $yesterday->setTimeFromTimeString((string) $end)->addMinutes($index % 20);

                    if ($checkOut->lt($checkIn)) {
                        $checkOut = $checkOut->addDay();
                    }

                    $rows[] = [
                        $column => $schedule->{$column},
                        'shift_id' => $shift->id,
                        'company_location_id' => $location?->id,
                        'date' => $yesterday->toDateString(),
                        'segment' => $segment,
                        'check_in' => $checkIn->toDateTimeString(),
                        'check_out' => $checkOut->toDateTimeString(),
                        'check_in_lat' => $location?->latitude,
                        'check_in_lng' => $location?->longitude,
                        'check_out_lat' => $location?->latitude,
                        'check_out_lng' => $location?->longitude,
                        'status' => $lateMinutes > 0 ? AttendanceStatus::Late->value : AttendanceStatus::OnTime->value,
                        'late_minutes' => $lateMinutes,
                        'notes' => null,
                        'is_corrected' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('attendances')->upsert($chunk, [$column, 'date', 'segment'], ['shift_id', 'company_location_id', 'check_in', 'check_out', 'status', 'late_minutes', 'updated_at']);
            }
        }
    }

    /**
     * Pending leave, overtime and correction requests with their approval steps, made once.
     */
    private function seedPendingRequests(): void
    {
        if (LeaveRequest::query()->where('reason', 'like', self::REQUEST_MARKER.'%')->exists()) {
            return;
        }

        $service = app(ApprovalFlowService::class);
        $yesterday = $this->today->subDay();
        $nextWeek = $this->today->addWeek()->startOfWeek();

        foreach ($this->companies as $key => $company) {
            $perCompany = $key === 'KB' ? 3 : 12;
            $employees = Employee::query()->where('company_id', $company->id)->where('nip', 'like', '%-EMP-%')->orderBy('id')->limit($perCompany * 3)->get();
            $interns = Intern::query()->where('company_id', $company->id)->orderBy('id')->limit(2)->get();
            $freelancers = Freelancer::query()->where('company_id', $company->id)->orderBy('id')->limit(2)->get();

            foreach ($employees->take($perCompany) as $index => $employee) {
                $type = $employee->has_annual_leave && $index % 3 === 0 ? 'annual' : ($index % 2 === 0 ? 'permission' : 'sick');
                $leave = LeaveRequest::query()->create([
                    'employee_id' => $employee->id,
                    'leave_type' => $type,
                    'start_date' => $nextWeek->addDays($index % 4)->toDateString(),
                    'end_date' => $nextWeek->addDays($index % 4)->toDateString(),
                    'days_count' => 1,
                    'reason' => self::REQUEST_MARKER.' Keperluan keluarga',
                    'status' => 'pending',
                ]);
                $service->generateSteps($leave, 'leave');
            }

            foreach ($interns->concat($freelancers) as $worker) {
                $leave = LeaveRequest::query()->create([
                    ($worker instanceof Intern ? 'intern_id' : 'freelancer_id') => $worker->id,
                    'leave_type' => 'permission',
                    'start_date' => $nextWeek->addDay()->toDateString(),
                    'end_date' => $nextWeek->addDay()->toDateString(),
                    'days_count' => 1,
                    'reason' => self::REQUEST_MARKER.' Izin kampus / urusan pribadi',
                    'status' => 'pending',
                ]);
                $service->generateSteps($leave, 'leave');
            }

            foreach ($employees->slice($perCompany, $perCompany) as $employee) {
                $overtime = OvertimeRequest::query()->create([
                    'employee_id' => $employee->id,
                    'date' => $yesterday->toDateString(),
                    'start_time' => '17:00:00',
                    'end_time' => '19:00:00',
                    'duration_minutes' => 120,
                    'reason' => self::REQUEST_MARKER.' Stock opname',
                    'status' => 'pending',
                ]);
                $service->generateSteps($overtime, 'overtime');
            }

            foreach ($employees->slice($perCompany * 2, $perCompany) as $employee) {
                $attendance = Attendance::query()->where('employee_id', $employee->id)->whereDate('date', $yesterday->toDateString())->where('segment', 1)->first();
                $correction = AttendanceCorrection::query()->create([
                    'employee_id' => $employee->id,
                    'attendance_id' => $attendance?->id,
                    'date' => $yesterday->toDateString(),
                    'corrected_check_in' => $yesterday->setTime(8, 0)->toDateTimeString(),
                    'corrected_check_out' => $yesterday->setTime(17, 0)->toDateTimeString(),
                    'reason' => self::REQUEST_MARKER.' Lupa absen / sinyal hilang',
                    'status' => 'pending',
                ]);
                $service->generateSteps($correction, 'correction');
            }
        }
    }

    private function printCounts(float $seconds): void
    {
        $this->command?->info(sprintf('QaDailyOpsSeeder selesai dalam %.1f detik.', $seconds));

        $rows = [];

        foreach ($this->companies as $key => $company) {
            $rows[] = [
                $key,
                $company->code,
                self::COMPANIES[$key]['mode'],
                Employee::query()->where('company_id', $company->id)->count(),
                Intern::query()->where('company_id', $company->id)->count(),
                Freelancer::query()->where('company_id', $company->id)->count(),
                CompanyLocation::query()->where('company_id', $company->id)->count(),
            ];
        }

        $this->command?->table(['Key', 'Kode', 'Mode', 'Karyawan', 'Magang', 'Freelance', 'Lokasi'], $rows);
        $this->command?->table(['Data', 'Jumlah'], [
            ['Users', User::query()->count()],
            ['Shift schedules', DB::table('shift_schedules')->count()],
            ['Attendances (kemarin)', Attendance::query()->whereDate('date', $this->today->subDay()->toDateString())->count()],
            ['Leave pending', LeaveRequest::query()->where('status', 'pending')->count()],
            ['Overtime pending', OvertimeRequest::query()->where('status', 'pending')->count()],
            ['Correction pending', AttendanceCorrection::query()->where('status', 'pending')->count()],
            ['Approval steps', DB::table('approval_request_steps')->count()],
        ]);
    }
}
