<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTeamFreelancerRequest;
use App\Http\Requests\Api\V1\UpdateTeamScheduleRequest;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\RosterLiveStateService;
use App\Services\ScheduleCopyService;
use App\Services\SchedulingOptions;
use App\Services\ShiftAssignmentService;
use App\Services\TeamRecapService;
use App\Services\WeeklyScheduleService;
use App\Services\WorkerAccountService;
use App\Services\WorkScheduleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * The coordinator's day in the app: today's roster per outlet, changing one schedule, and taking
 * freelancers on or letting them go. Everything stays inside the user's data access and brands.
 */
class TeamApiController extends Controller
{
    /** @var array<string, array{model: class-string, code: string}> */
    private const WORKER_TYPES = [
        'employee' => ['model' => Employee::class, 'code' => 'nip'],
        'intern' => ['model' => Intern::class, 'code' => 'nis'],
        'freelancer' => ['model' => Freelancer::class, 'code' => 'freelancer_number'],
    ];

    /** Roster state of a scheduled worker on approved leave who has not checked in. */
    public const STATE_LEAVE = RosterLiveStateService::STATE_LEAVE;

    private const BORROWED_MESSAGE = 'Pekerja ini dipinjam dari brand lain. Jadwalnya diatur koordinator brand asalnya.';

    public function __construct(
        private readonly SchedulingOptions $options,
        private readonly WorkerAccountService $accounts,
    ) {}

    /**
     * The day per outlet. On top of the dated schedules it shows who is between the segments of a split
     * shift, who is still on last night's shift, and who checked in without a schedule ("Tanpa jadwal"),
     * with counts rebuilt from the rows so the header and the list agree.
     */
    public function roster(Request $request, RosterLiveStateService $liveState): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], ...$this->scheduleFilterRules()]);
        $date = Carbon::parse($validated['date'] ?? today()->toDateString());

        $roster = $liveState->roster($user, $date, $this->scheduleFilters($validated));

        return response()->json([
            'date' => $date->toDateString(),
            'outlets' => $roster['outlets'],
            // Distinct people: someone with two schedules today counts once here (the outlet counts are per schedule).
            'summary' => $roster['summary'],
        ]);
    }

    /**
     * The week (Monday to Sunday) holding the date, one line per worker with a cell per day.
     */
    public function week(Request $request, WeeklyScheduleService $weeks, RosterLiveStateService $liveState): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], ...$this->scheduleFilterRules()]);

        $week = $weeks->week($user, Carbon::parse($validated['date'] ?? today()->toDateString()), $this->scheduleFilters($validated));
        $editable = $liveState->editableWorkers($user, collect($week['workers'])->pluck('worker'));
        // A worker listed only for a leave has no cells: days stay a JSON object, as for everyone else.
        $week['workers'] = array_map(fn (array $row): array => [
            ...$row,
            'days' => $row['days'] === [] ? (object) [] : $row['days'],
            'day_schedules' => $row['day_schedules'] === [] ? (object) [] : $row['day_schedules'],
            'can_edit' => isset($editable[$this->workerKey($row['worker'])]),
        ], $week['workers']);

        return response()->json($week);
    }

    /**
     * Optional filters of the roster and the week: one company, brand (division) or outlet.
     *
     * @return array<string, list<string>>
     */
    private function scheduleFilterRules(): array
    {
        return [
            'company_id' => ['nullable', 'integer'],
            'division_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{company_id: int|null, division_id: int|null, location_id: int|null}
     */
    private function scheduleFilters(array $validated): array
    {
        return [
            'company_id' => isset($validated['company_id']) ? (int) $validated['company_id'] : null,
            'division_id' => isset($validated['division_id']) ? (int) $validated['division_id'] : null,
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
        ];
    }

    public function options(Request $request): JsonResponse
    {
        $user = $this->manager($request);

        return response()->json([
            'companies' => $this->options->companies($user)->map(fn ($company): array => [
                'id' => (int) $company->id,
                'name' => $company->name,
                'outlets' => $this->options->locations($user, $company->id, borrowing: true)->map(fn (CompanyLocation $location): array => [
                    'id' => (int) $location->id,
                    'name' => $location->name,
                    'brand' => $location->division?->name,
                    // False for another brand's outlet, which a coordinator may only lend a worker to.
                    'own_brand' => $this->options->ownBrandIds($user) === null || in_array((int) $location->division_id, $this->options->ownBrandIds($user), true),
                ])->values(),
                'shifts' => $this->options->shifts($user, $company->id, borrowing: true)->map(fn (Shift $shift): array => [
                    'id' => (int) $shift->id,
                    'name' => $shift->name,
                    'code' => $shift->code,
                    'talenta_code' => $shift->talenta_code,
                    'brand' => $shift->division?->name,
                    'label' => $shift->label(),
                    'start_time' => substr((string) $shift->start_time, 0, 5),
                    'end_time' => substr((string) $shift->end_time, 0, 5),
                    'segments' => $shift->segments(),
                ])->values(),
                'sections' => $this->options->sections($user, $company->id),
            ])->values(),
        ]);
    }

    /**
     * Active workers in the user's data access, for picking someone to schedule.
     */
    public function workers(Request $request): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) ($validated['search'] ?? ''));
        $workers = collect();

        foreach (self::WORKER_TYPES as $type => $definition) {
            DataAccessService::scopeWorkers($definition['model']::query(), $user, includeOwn: false)
                ->whereNotIn('status', WorkerAccountService::ENDED_STATUSES)
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere($definition['code'], 'like', "%{$search}%")))
                ->orderBy('full_name')
                ->limit(50)
                ->get(['id', 'company_id', 'full_name', $definition['code']])
                ->each(fn ($worker) => $workers->push([
                    'type' => $type,
                    'id' => (int) $worker->id,
                    'company_id' => (int) $worker->company_id,
                    'number' => (string) $worker->{$definition['code']},
                    'name' => $worker->full_name,
                ]));
        }

        return response()->json(['data' => $workers->sortBy('name')->values()->take(50)]);
    }

    public function updateSchedule(UpdateTeamScheduleRequest $request, ShiftAssignmentService $assignments): JsonResponse
    {
        $data = $request->validated();
        $worker = $this->findWorker($request->user(), $data['worker_type'], (int) $data['worker_id'], scheduling: true);

        $schedule = $assignments->assignDay(
            $request->user(),
            $worker,
            $data['date'],
            $data['mode'],
            isset($data['shift_id']) ? (int) $data['shift_id'] : null,
            isset($data['company_location_id']) ? (int) $data['company_location_id'] : null,
            $data['section'] ?? null,
            reason: $data['reason'] ?? null,
            source: 'api',
            scheduleId: isset($data['schedule_id']) ? (int) $data['schedule_id'] : null,
            add: (bool) ($data['add'] ?? false),
        );

        return response()->json([
            'message' => $schedule ? 'Jadwal disimpan.' : 'Jadwal dihapus.',
            'data' => $schedule ? [...$this->scheduleData($schedule), 'sequence' => (int) ($schedule->sequence ?: 1)] : null,
            // Every schedule of the worker on that date after the change, in day order.
            'schedules' => $assignments->rowsOn($worker, $data['date'])
                ->map(fn (ShiftSchedule $row): array => [...$this->scheduleData($row), 'sequence' => (int) ($row->sequence ?: 1), 'locked' => $row->isLocked()])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array{id: int, date: string, is_day_off: bool, shift_id: int|null, company_location_id: int|null, section: string|null}
     */
    private function scheduleData(ShiftSchedule $schedule): array
    {
        return [
            'id' => (int) $schedule->id,
            'date' => $schedule->date->toDateString(),
            'is_day_off' => (bool) $schedule->is_day_off,
            'shift_id' => $schedule->shift_id !== null ? (int) $schedule->shift_id : null,
            'company_location_id' => $schedule->company_location_id !== null ? (int) $schedule->company_location_id : null,
            'section' => $schedule->section,
        ];
    }

    /**
     * Copy one week of schedules onto another (any date inside each week); days already filled are kept.
     */
    public function copySchedules(Request $request, ScheduleCopyService $copies): JsonResponse
    {
        $user = $this->manager($request);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);

        $result = DB::transaction(fn (): array => $copies->copyWeek($user, $from, $to));

        return response()->json(['message' => $copies->summary($result), 'data' => $result]);
    }

    public function recap(Request $request, TeamRecapService $recaps): JsonResponse
    {
        $user = $this->manager($request);
        $month = $this->month($request);

        $report = $recaps->report($user, $month);

        return response()->json([
            'month' => $month->format('Y-m'),
            'data' => $report['rows'],
            // Distinct worker-days: a day worked at two outlets counts once here.
            'total' => $report['total'],
        ]);
    }

    /**
     * A signed link, valid for 15 minutes, that downloads the recap as Excel without an API token.
     */
    public function recapLink(Request $request): JsonResponse
    {
        $user = $this->manager($request);
        $month = $this->month($request);

        return response()->json([
            'url' => URL::temporarySignedRoute('exports.team-recap', now()->addMinutes(15), ['user' => $user->id, 'month' => $month->format('Y-m')]),
            'expires_in' => 900,
        ]);
    }

    public function freelancers(Request $request): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $validated['status'] ?? 'active';
        $search = trim((string) ($validated['search'] ?? ''));

        $freelancers = DataAccessService::scopeWorkers(Freelancer::query()->with('division'), $user, includeOwn: false)
            ->when($status === 'ended', fn (Builder $query) => $query->whereIn('status', WorkerAccountService::ENDED_STATUSES))
            ->when($status !== 'ended', fn (Builder $query) => $query->whereNotIn('status', WorkerAccountService::ENDED_STATUSES))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('freelancer_number', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")))
            ->orderBy('full_name')
            ->paginate(30);

        return response()->json([
            'data' => collect($freelancers->items())->map(fn (Freelancer $freelancer): array => $this->freelancerData($freelancer))->values(),
            'pagination' => [
                'current_page' => $freelancers->currentPage(),
                'last_page' => $freelancers->lastPage(),
                'total' => $freelancers->total(),
            ],
        ]);
    }

    public function storeFreelancer(StoreTeamFreelancerRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $outlet = CompanyLocation::query()->find($data['company_location_id']);

        if (! $outlet || ! $this->options->locations($user, $outlet->company_id)->contains('id', $outlet->id)) {
            return $this->fieldError('company_location_id', 'Outlet ini tidak bisa dipilih.');
        }

        if (! $outlet->division_id || ! $user->canManagePlacement($outlet->company_id, $outlet->division_id)) {
            return $this->fieldError('company_location_id', 'Outlet ini belum punya brand. Minta admin mengisinya di Lokasi & Cabang.');
        }

        $freelancer = DB::transaction(fn (): Freelancer => Freelancer::create([
            'company_id' => $outlet->company_id,
            'division_id' => $outlet->division_id,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'start_date' => $data['start_date'] ?? today()->toDateString(),
            'status' => 'active',
        ]));

        try {
            $account = $this->accounts->createAccount($freelancer);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => "Freelancer disimpan, tapi akun belum dibuat: {$exception->getMessage()}",
                'data' => $this->freelancerData($freelancer->fresh(['division'])),
                'account' => null,
            ], 201);
        }

        return response()->json([
            'message' => $account['whatsapp']['api_sent'] ? 'Freelancer ditambahkan. Akun terkirim lewat WhatsApp.' : 'Freelancer ditambahkan. Kirim akun ini ke freelancer.',
            'data' => $this->freelancerData($freelancer->fresh(['division'])),
            'account' => $this->accountData($account),
        ], 201);
    }

    public function createFreelancerAccount(Request $request, int $id): JsonResponse
    {
        $freelancer = $this->findWorker($this->manager($request), 'freelancer', $id);

        if ($freelancer->user_id) {
            return response()->json(['message' => 'Freelancer ini sudah punya akun.'], 409);
        }

        try {
            $account = $this->accounts->createAccount($freelancer);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $account['whatsapp']['api_sent'] ? 'Akun dibuat dan terkirim lewat WhatsApp.' : 'Akun dibuat. Kirim akun ini ke freelancer.',
            'data' => $this->freelancerData($freelancer->fresh(['division'])),
            'account' => $this->accountData($account),
        ], 201);
    }

    public function endFreelancer(Request $request, int $id): JsonResponse
    {
        $freelancer = $this->findWorker($this->manager($request), 'freelancer', $id);
        $this->accounts->endContract($freelancer);

        return response()->json(['message' => 'Kontrak diakhiri.', 'data' => $this->freelancerData($freelancer->fresh(['division']))]);
    }

    public function reactivateFreelancer(Request $request, int $id): JsonResponse
    {
        $freelancer = $this->findWorker($this->manager($request), 'freelancer', $id);

        try {
            // Refused when the number was given to someone else while the contract was closed.
            $this->accounts->reactivate($freelancer);
        } catch (InvalidArgumentException $exception) {
            return $this->fieldError('phone', $exception->getMessage().' Ubah nomor HP-nya dulu.');
        }

        return response()->json(['message' => 'Freelancer aktif lagi.', 'data' => $this->freelancerData($freelancer->fresh(['division']))]);
    }

    private function month(Request $request): Carbon
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return isset($validated['month']) ? TeamRecapService::parseMonth($validated['month']) : today()->startOfMonth();
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->canManageWorkers(), 403, 'Fitur tim hanya untuk koordinator.');

        return $user;
    }

    /**
     * The worker the user manages. When scheduling, a worker the user only sees because they are lent
     * to one of the user's outlets gets a 403 that says who plans them, instead of "not found".
     */
    private function findWorker(User $user, string $type, int $id, bool $scheduling = false): Employee|Intern|Freelancer
    {
        $worker = self::WORKER_TYPES[$type]['model']::query()->find($id);

        if ($worker && ! $user->canManageWorker($worker) && $scheduling && $this->isBorrowedInto($user, $worker)) {
            abort(403, self::BORROWED_MESSAGE);
        }

        abort_unless($worker && $user->canManageWorker($worker), 404, 'Pekerja tidak ditemukan.');

        return $worker;
    }

    /**
     * Whether the worker has a schedule the user sees, i.e. they are lent to one of the user's outlets.
     */
    private function isBorrowedInto(User $user, Employee|Intern|Freelancer $worker): bool
    {
        return ShiftSchedule::query()
            ->visibleTo($user)
            ->where(app(WorkScheduleService::class)->workerColumn($worker), $worker->getKey())
            ->exists();
    }

    /**
     * @param  array{type: string, id: int}  $worker
     */
    private function workerKey(array $worker): string
    {
        return "{$worker['type']}:{$worker['id']}";
    }

    /**
     * @return array<string, mixed>
     */
    private function freelancerData(Freelancer $freelancer): array
    {
        return [
            'id' => (int) $freelancer->id,
            'company_id' => (int) $freelancer->company_id,
            'number' => $freelancer->freelancer_number,
            'name' => $freelancer->full_name,
            'phone' => $freelancer->phone,
            'brand' => $freelancer->division?->name,
            'status' => $freelancer->status,
            // Not ended: a contract that starts later is ended with "Akhiri", not taken back on.
            'is_active' => ! $this->accounts->hasEnded($freelancer),
            'has_started' => $this->accounts->hasStarted($freelancer),
            'contract_state' => match (true) {
                $this->accounts->hasEnded($freelancer) => 'ended',
                ! $this->accounts->hasStarted($freelancer) => 'not_started',
                default => 'active',
            },
            'contract_state_label' => match (true) {
                $this->accounts->hasEnded($freelancer) => 'Berakhir',
                ! $this->accounts->hasStarted($freelancer) => 'Belum mulai',
                default => 'Aktif',
            },
            'has_account' => $freelancer->user_id !== null,
            'start_date' => $freelancer->start_date?->toDateString(),
            'end_date' => $freelancer->end_date?->toDateString(),
        ];
    }

    /**
     * @param  array{email: string, password: string, whatsapp: array<string, mixed>}  $account
     * @return array{email: string, password: string, whatsapp_sent: bool, whatsapp_url: string|null}
     */
    private function accountData(array $account): array
    {
        return [
            'email' => $account['email'],
            'password' => $account['password'],
            'whatsapp_sent' => (bool) $account['whatsapp']['api_sent'],
            'whatsapp_url' => $account['whatsapp']['wa_url'] ?? null,
        ];
    }

    private function fieldError(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
