<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTeamFreelancerRequest;
use App\Http\Requests\Api\V1\UpdateTeamScheduleRequest;
use App\Models\Attendance;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\OutletRosterService;
use App\Services\RosterLiveStateService;
use App\Services\ScheduleCopyService;
use App\Services\SchedulingOptions;
use App\Services\ShiftAssignmentService;
use App\Services\TeamRecapService;
use App\Services\WeeklyScheduleService;
use App\Services\WorkerAccountService;
use App\Services\WorkScheduleService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    public const STATE_LEAVE = 'cuti';

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
    public function roster(Request $request, OutletRosterService $roster, RosterLiveStateService $liveState): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], ...$this->scheduleFilterRules()]);
        $date = Carbon::parse($validated['date'] ?? today()->toDateString());
        $filters = $this->scheduleFilters($validated);

        $outlets = $roster->roster($user, $date, $filters);
        $entries = collect($outlets)->flatMap(fn (array $outlet): array => $outlet['entries']);
        $editable = $this->editableWorkers($user, $entries->pluck('worker'));
        $onLeave = $this->workersOnLeave($entries->pluck('worker'), $date);
        $outlets = array_map(fn (array $outlet): array => $this->withLeaveAndEditFlags($outlet, $editable, $onLeave), $outlets);

        return response()->json([
            'date' => $date->toDateString(),
            'outlets' => $liveState->apply($user, $date, $outlets, $filters),
        ]);
    }

    /**
     * The week (Monday to Sunday) holding the date, one line per worker with a cell per day.
     */
    public function week(Request $request, WeeklyScheduleService $weeks): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], ...$this->scheduleFilterRules()]);

        $week = $weeks->week($user, Carbon::parse($validated['date'] ?? today()->toDateString()), $this->scheduleFilters($validated));
        $editable = $this->editableWorkers($user, collect($week['workers'])->pluck('worker'));
        $week['workers'] = array_map(fn (array $row): array => [...$row, 'can_edit' => isset($editable[$this->workerKey($row['worker'])])], $week['workers']);

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

        if ($data['mode'] !== ShiftAssignmentService::MODE_CLEAR && ($outsideContract = $this->outsideContract($worker, Carbon::parse($data['date']))) !== null) {
            return $this->fieldError('date', $outsideContract);
        }

        $schedule = $assignments->assignDay(
            $request->user(),
            $worker,
            $data['date'],
            $data['mode'],
            isset($data['shift_id']) ? (int) $data['shift_id'] : null,
            isset($data['company_location_id']) ? (int) $data['company_location_id'] : null,
            $data['section'] ?? null,
        );

        return response()->json([
            'message' => $schedule ? 'Jadwal disimpan.' : 'Jadwal dihapus.',
            'data' => $schedule ? [
                'id' => (int) $schedule->id,
                'date' => $schedule->date->toDateString(),
                'is_day_off' => $schedule->is_day_off,
                'shift_id' => $schedule->shift_id,
                'company_location_id' => $schedule->company_location_id,
                'section' => $schedule->section,
            ] : null,
        ]);
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

        $result = DB::transaction(function () use ($copies, $user, $from, $to): array {
            $outsideContract = $this->copiesOutsideContract($user, $from, $to);
            $result = $copies->copyWeek($user, $from, $to);
            $removed = 0;

            foreach ($outsideContract as $slot) {
                // Deleted one by one so the ShiftSchedule deleted hook recalculates lateness of that day.
                ShiftSchedule::query()
                    ->where($slot['column'], $slot['worker_id'])
                    ->whereDate('date', $slot['date'])
                    ->where('created_by', $user->id)
                    ->get()
                    ->each(function (ShiftSchedule $schedule) use (&$removed): void {
                        $schedule->delete();
                        $removed++;
                    });
            }

            $result['copied'] -= $removed;
            $result['skipped_ended'] = $removed;

            return $result;
        });

        $message = $copies->summary($result);

        if ($result['skipped_ended'] > 0) {
            $message .= " {$result['skipped_ended']} dilewati karena di luar masa kontrak pekerja.";
        }

        return response()->json(['message' => $message, 'data' => $result]);
    }

    /**
     * The days a week copy would give to a worker outside their contract (ended, or before it starts),
     * limited to days that are still empty so nothing the copy keeps is touched afterwards.
     *
     * @return list<array{column: string, worker_id: int, date: string}>
     */
    private function copiesOutsideContract(User $user, Carbon $from, Carbon $to): array
    {
        $source = $from->copy()->startOfWeek();
        $offsetDays = (int) $source->diffInDays($to->copy()->startOfWeek(), false);

        if ($offsetDays === 0) {
            return [];
        }

        $columns = app(WorkScheduleService::class);
        $slots = [];

        ShiftSchedule::query()
            ->visibleTo($user)
            ->with(ShiftSchedule::workerEagerLoads())
            ->where('date', '>=', $source->toDateString())
            ->where('date', '<', $source->copy()->addDays(7)->toDateString())
            ->get()
            ->each(function (ShiftSchedule $schedule) use ($user, $offsetDays, $columns, &$slots): void {
                $worker = $schedule->getWorkerProfile();
                $date = $schedule->date->copy()->addDays($offsetDays);

                if (! $worker || ! $user->canManageWorker($worker) || $this->outsideContract($worker, $date) === null) {
                    return;
                }

                $column = $columns->workerColumn($worker);

                if (ShiftSchedule::query()->where($column, $worker->getKey())->whereDate('date', $date->toDateString())->exists()) {
                    return;
                }

                $slots[] = ['column' => $column, 'worker_id' => (int) $worker->getKey(), 'date' => $date->toDateString()];
            });

        return $slots;
    }

    /**
     * Why the worker cannot be scheduled on the date, or null when they can: an ended contract, a day
     * after the contract's end date, or a day before it starts.
     */
    private function outsideContract(Employee|Intern|Freelancer $worker, CarbonInterface $date): ?string
    {
        $endDate = $worker instanceof Employee ? null : $worker->end_date;
        $startDate = $worker instanceof Employee ? null : $worker->start_date;

        if ($endDate !== null && $date->toDateString() > $endDate->toDateString()) {
            return 'Kontrak pekerja ini berakhir '.$endDate->locale('id')->translatedFormat('d F Y').'. Hari setelahnya tidak bisa dijadwalkan.';
        }

        if ($endDate === null && in_array($worker->status, WorkerAccountService::ENDED_STATUSES, true)) {
            return 'Kontrak pekerja ini sudah berakhir.';
        }

        if ($startDate !== null && $date->toDateString() < $startDate->toDateString()) {
            return 'Kontrak pekerja ini baru mulai '.$startDate->locale('id')->translatedFormat('d F Y').'. Hari sebelumnya tidak bisa dijadwalkan.';
        }

        return null;
    }

    public function recap(Request $request, TeamRecapService $recaps): JsonResponse
    {
        $user = $this->manager($request);
        $month = $this->month($request);

        return response()->json([
            'month' => $month->format('Y-m'),
            'data' => $recaps->recap($user, $month),
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

        return isset($validated['month']) ? Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth() : today()->startOfMonth();
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
     * Keys ("freelancer:12") of the listed workers whose schedule the user may change. Workers lent in
     * from another brand are only shown; their own brand plans them.
     *
     * @param  Collection<int, array{type: string, id: int}>  $workers
     * @return array<string, true>
     */
    private function editableWorkers(User $user, Collection $workers): array
    {
        $editable = [];

        foreach ($workers->groupBy('type') as $type => $ofType) {
            self::WORKER_TYPES[$type]['model']::query()
                ->whereKey($ofType->pluck('id')->unique()->all())
                ->get()
                ->filter(fn ($worker): bool => $user->canManageWorker($worker))
                ->each(function ($worker) use ($type, &$editable): void {
                    $editable["{$type}:{$worker->getKey()}"] = true;
                });
        }

        return $editable;
    }

    /**
     * Keys of the listed workers on approved leave that day (an approved request, or the leave row it wrote).
     *
     * @param  Collection<int, array{type: string, id: int}>  $workers
     * @return array<string, true>
     */
    private function workersOnLeave(Collection $workers, CarbonInterface $date): array
    {
        $onLeave = [];
        $day = $date->toDateString();

        foreach ($workers->groupBy('type') as $type => $ofType) {
            $ids = $ofType->pluck('id')->unique()->all();
            $column = "{$type}_id";

            LeaveRequest::query()
                ->where('status', 'approved')
                ->whereIn($column, $ids)
                ->whereDate('start_date', '<=', $day)
                ->whereDate('end_date', '>=', $day)
                ->pluck($column)
                ->merge(Attendance::query()->whereIn($column, $ids)->whereDate('date', $day)->where('status', AttendanceStatus::Leave)->pluck($column))
                ->each(function ($id) use ($type, &$onLeave): void {
                    $onLeave["{$type}:{$id}"] = true;
                });
        }

        return $onLeave;
    }

    /**
     * Marks each entry editable or not and shows a worker on approved leave who has not checked in as
     * "cuti" instead of "belum absen", so the outlet's counts and the home card stay right.
     *
     * @param  array{location: mixed, counts: array<string, int>, entries: list<array<string, mixed>>}  $outlet
     * @param  array<string, true>  $editable
     * @param  array<string, true>  $onLeave
     * @return array<string, mixed>
     */
    private function withLeaveAndEditFlags(array $outlet, array $editable, array $onLeave): array
    {
        $outlet['counts'][self::STATE_LEAVE] = 0;

        foreach ($outlet['entries'] as $index => $entry) {
            $key = $this->workerKey($entry['worker']);
            $outlet['entries'][$index]['can_edit'] = isset($editable[$key]);

            if (isset($onLeave[$key]) && in_array($entry['state'], [OutletRosterService::STATE_MISSING, OutletRosterService::STATE_UPCOMING], true)) {
                $outlet['entries'][$index]['state'] = self::STATE_LEAVE;
                $outlet['counts'][$entry['state']]--;
                $outlet['counts'][self::STATE_LEAVE]++;
                $outlet['counts']['scheduled']--;
            }
        }

        return $outlet;
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
