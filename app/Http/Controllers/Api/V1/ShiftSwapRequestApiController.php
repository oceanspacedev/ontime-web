<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreShiftSwapRequest;
use App\Http\Resources\Api\V1\ShiftSwapRequestResource;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\ShiftSchedule;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\ShiftSwapService;
use App\Services\WorkerAccountService;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Tukar Shift for workers: their sent and received requests, colleagues to pick from, filing a request and
 * the colleague's answer. Approvers decide through /approvals like every other request.
 */
class ShiftSwapRequestApiController extends Controller
{
    /**
     * Relations the resource reads.
     *
     * @var list<string>
     */
    private const EAGER_LOADS = [
        'employee.division', 'intern.division', 'freelancer.division',
        'counterpartEmployee.division', 'counterpartIntern.division', 'counterpartFreelancer.division',
        'approvalSteps',
    ];

    /** Colleagues listed per worker type; the app searches by name beyond that. */
    private const COLLEAGUE_LIMIT = 200;

    public function __construct(private ShiftSwapService $shiftSwaps) {}

    /**
     * Nothing is written on read: an open request whose date passed is shown as expired (effectiveStatus) until
     * the shift-swaps:expire command saves it.
     */
    public function index(Request $request): JsonResponse
    {
        $box = in_array($request->query('box'), ['sent', 'received'], true) ? (string) $request->query('box') : 'all';

        $swaps = ShiftSwapRequest::query()
            ->involving($request->user(), $box)
            ->with(self::EAGER_LOADS)
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => ShiftSwapRequestResource::collection($swaps),
            'awaiting_my_answer' => ShiftSwapRequest::query()
                ->involving($request->user(), 'received')
                ->where('status', ShiftSwapRequest::STATUS_AWAITING_COLLEAGUE)
                ->notOverdue()
                ->count(),
            'pagination' => [
                'current_page' => $swaps->currentPage(),
                'last_page' => $swaps->lastPage(),
                'total' => $swaps->total(),
            ],
        ]);
    }

    /**
     * Active colleagues of the worker's company who have an account, with their schedule on the date, so the
     * app can show who works and who is off.
     */
    public function colleagues(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ], [
            'date.required' => 'Pilih tanggal.',
            'date.date_format' => 'Format tanggal tidak valid.',
        ]);

        $me = $this->shiftSwaps->requesterProfileOf($request->user());

        if (! $me) {
            return response()->json(['message' => 'Profil pekerja Anda tidak ditemukan.'], 422);
        }

        $date = Carbon::parse($validated['date']);
        $search = trim((string) ($validated['search'] ?? ''));

        // Every exclusion is done in SQL, before the limit, so inactive or ended workers never crowd out active
        // colleagues; a larger company narrows the list with the search field.
        $workers = collect([Employee::class, Intern::class, Freelancer::class])
            ->flatMap(fn (string $modelClass): Collection => $modelClass::query()
                ->with(['division', 'shift', 'user'])
                ->where('company_id', $me->company_id)
                ->whereNotNull('user_id')
                ->when($me instanceof $modelClass, fn ($query) => $query->whereKeyNot($me->getKey()))
                ->where(fn ($status) => $status->whereNull('status')->orWhereNotIn('status', WorkerAccountService::ENDED_STATUSES))
                ->when($modelClass !== Employee::class, fn ($query) => $query->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $date->toDateString())))
                ->whereHas('user', fn ($user) => $user->where('is_active', true))
                ->when($search !== '', fn ($query) => $query->where('full_name', 'like', "%{$search}%"))
                ->orderBy('full_name')
                ->limit(self::COLLEAGUE_LIMIT)
                ->get())
            ->sortBy('full_name')
            ->values();

        $schedules = $this->schedulesOn($workers, $date);

        return response()->json([
            'data' => $workers->map(function ($worker) use ($schedules): array {
                $key = app(WorkScheduleService::class)->workerColumn($worker).':'.$worker->id;
                $schedule = $schedules->get($key);
                $state = $this->shiftSwaps->cellState($worker, $schedule);
                $shift = $schedule?->shift ?? ($state['shift_id'] ? $worker->shift : null);

                return [
                    'type' => ShiftSwapService::workerType($worker),
                    'id' => (int) $worker->id,
                    'full_name' => $worker->full_name,
                    'division' => $worker->division?->name,
                    'is_off' => $state['off'],
                    'schedule_label' => $state['off'] ? 'Libur' : ($shift?->label() ?? 'Belum dijadwalkan'),
                ];
            })->all(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::query()->with(self::EAGER_LOADS)->find($id);

        if (! $swap || ! $this->canView($swap, $request->user())) {
            return response()->json(['message' => 'Pengajuan tukar shift tidak ditemukan.'], 404);
        }

        return response()->json(['data' => new ShiftSwapRequestResource($swap)]);
    }

    public function store(StoreShiftSwapRequest $request): JsonResponse
    {
        $requester = $this->shiftSwaps->requesterProfileOf($request->user());

        if (! $requester) {
            return response()->json(['message' => 'Profil pekerja Anda tidak ditemukan.'], 422);
        }

        $counterpart = $this->shiftSwaps->findWorker((string) $request->input('colleague_type'), (int) $request->input('colleague_id'));

        // Another company's worker is answered like a missing one: nothing about them is revealed.
        if (! $counterpart || (int) $counterpart->company_id !== (int) $requester->company_id) {
            return response()->json([
                'message' => 'Rekan kerja tidak ditemukan di badan usaha Anda.',
                'errors' => ['colleague_id' => ['Rekan kerja tidak ditemukan di badan usaha Anda.']],
            ], 422);
        }

        $swap = $this->shiftSwaps->create(
            $requester,
            $counterpart,
            (string) $request->input('type'),
            Carbon::parse($request->input('requester_date')),
            $request->filled('counterpart_date') ? Carbon::parse($request->input('counterpart_date')) : null,
            $request->input('reason'),
        );

        return response()->json([
            'message' => 'Ajakan tukar shift terkirim. Menunggu jawaban rekan Anda.',
            'data' => new ShiftSwapRequestResource($swap->load(self::EAGER_LOADS)),
        ], 201);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $swap = $this->findInvolving($request->user(), $id);

        if (! $swap) {
            return response()->json(['message' => 'Pengajuan tukar shift tidak ditemukan.'], 404);
        }

        $swap = $this->shiftSwaps->accept($swap, $request->user());

        return response()->json([
            'message' => 'Tukar shift diterima. Menunggu persetujuan atasan.',
            'data' => new ShiftSwapRequestResource($swap->fresh(self::EAGER_LOADS)),
        ]);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $swap = $this->findInvolving($request->user(), $id);

        if (! $swap) {
            return response()->json(['message' => 'Pengajuan tukar shift tidak ditemukan.'], 404);
        }

        $swap = $this->shiftSwaps->decline($swap, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Ajakan tukar shift ditolak.',
            'data' => new ShiftSwapRequestResource($swap->fresh(self::EAGER_LOADS)),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $swap = $this->findInvolving($request->user(), $id);

        if (! $swap) {
            return response()->json(['message' => 'Pengajuan tukar shift tidak ditemukan.'], 404);
        }

        $swap = $this->shiftSwaps->cancel($swap, $request->user());

        return response()->json([
            'message' => 'Pengajuan tukar shift dibatalkan.',
            'data' => new ShiftSwapRequestResource($swap->fresh(self::EAGER_LOADS)),
        ]);
    }

    private function findInvolving(User $user, int $id): ?ShiftSwapRequest
    {
        return ShiftSwapRequest::query()->involving($user)->with(self::EAGER_LOADS)->find($id);
    }

    /**
     * The two workers, and anyone who may open it in the approval inbox.
     */
    private function canView(ShiftSwapRequest $swap, User $user): bool
    {
        return $swap->isRequestedBy($user) || $swap->isCounterpart($user) || ApprovalApiController::canOpen($swap, $user);
    }

    /**
     * Schedule rows of the workers on the date, keyed "worker_column:id".
     *
     * @param  Collection<int, Employee|Intern|Freelancer>  $workers
     * @return Collection<string, ShiftSchedule>
     */
    private function schedulesOn(Collection $workers, Carbon $date): Collection
    {
        $idsByColumn = $workers->groupBy(fn ($worker): string => app(WorkScheduleService::class)->workerColumn($worker))
            ->map(fn (Collection $group): array => $group->pluck('id')->all());

        if ($idsByColumn->isEmpty()) {
            return collect();
        }

        return ShiftSchedule::query()
            ->with('shift')
            ->whereDate('date', $date->toDateString())
            ->where(function ($query) use ($idsByColumn): void {
                foreach ($idsByColumn as $column => $ids) {
                    $query->orWhereIn($column, $ids);
                }
            })
            ->get()
            ->keyBy(function (ShiftSchedule $schedule): string {
                $column = $schedule->employee_id ? 'employee_id' : ($schedule->intern_id ? 'intern_id' : 'freelancer_id');

                return $column.':'.$schedule->{$column};
            });
    }
}
