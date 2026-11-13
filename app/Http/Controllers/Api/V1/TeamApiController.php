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
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\OutletRosterService;
use App\Services\ScheduleCopyService;
use App\Services\SchedulingOptions;
use App\Services\ShiftAssignmentService;
use App\Services\TeamRecapService;
use App\Services\WeeklyScheduleService;
use App\Services\WorkerAccountService;
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

    public function __construct(
        private readonly SchedulingOptions $options,
        private readonly WorkerAccountService $accounts,
    ) {}

    public function roster(Request $request, OutletRosterService $roster): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = Carbon::parse($validated['date'] ?? today()->toDateString());

        return response()->json([
            'date' => $date->toDateString(),
            'outlets' => $roster->roster($user, $date),
        ]);
    }

    /**
     * The week (Monday to Sunday) holding the date, one line per worker with a cell per day.
     */
    public function week(Request $request, WeeklyScheduleService $weeks): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($weeks->week($user, Carbon::parse($validated['date'] ?? today()->toDateString())));
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
        $search = trim((string) $request->query('search', ''));
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
        $worker = $this->findWorker($request->user(), $data['worker_type'], (int) $data['worker_id']);

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
        $result = $copies->copyWeek($user, Carbon::parse($data['from']), Carbon::parse($data['to']));

        return response()->json(['message' => $copies->summary($result), 'data' => $result]);
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
        $status = $request->query('status', 'active');
        $search = trim((string) $request->query('search', ''));

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
        $this->accounts->reactivate($freelancer);

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

    private function findWorker(User $user, string $type, int $id): Employee|Intern|Freelancer
    {
        $worker = self::WORKER_TYPES[$type]['model']::query()->find($id);
        abort_unless($worker && $user->canManageWorker($worker), 404, 'Pekerja tidak ditemukan.');

        return $worker;
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
            'is_active' => $this->accounts->isActive($freelancer),
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
