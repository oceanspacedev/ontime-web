<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequestStep;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\Division;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalResolvedNotification;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class ApprovalFlowService
{
    /**
     * Request type keys with the labels used in notifications and default flow names.
     *
     * @var array<string, string>
     */
    public const REQUEST_TYPE_LABELS = [
        'leave' => 'Cuti / Izin',
        'overtime' => 'Lembur',
        'correction' => 'Koreksi Absensi',
        'resignation' => 'Pengunduran Diri',
        'geofence' => 'Presensi Luar Geofence',
        'shift_swap' => 'Tukar Shift',
    ];

    /**
     * Roles that can act on an approval stage; a stage set to any other role could never be approved.
     * The platform Superadmin is not one: each client approves its own requests.
     *
     * @var list<string>
     */
    public const APPROVER_ROLES = ['Approver', 'BOD', 'Admin', 'Koordinator'];

    /**
     * Roles that can ever have something in the approval inbox. Anyone else only sees requests on a
     * stage that names them directly.
     *
     * @var list<string>
     */
    public const INBOX_ROLES = ['Approver', 'BOD', 'Admin', 'Koordinator', 'Superadmin'];

    /**
     * Shown when a request changed (approved, rejected or moved to another stage) between the moment
     * the approver opened it and the moment they acted on it.
     */
    public const ALREADY_PROCESSED_MESSAGE = 'Pengajuan ini sudah diproses.';

    /**
     * Statuses of a request that is still waiting for an approval.
     *
     * @var list<string>
     */
    private const PENDING_STATUSES = ['pending', 'pending_approval'];

    /**
     * Users who can be named as a stage's approver for the company: active, holding an approval role,
     * and with data access reaching that company. Anyone else would approve requests they cannot see.
     *
     * @return array<int, string>
     */
    public static function approverUserOptions(int|string|null $companyId): array
    {
        if (blank($companyId)) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', self::APPROVER_ROLES))
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'Superadmin'))
            ->orderBy('name')
            ->get()
            ->filter(function (User $user) use ($companyId): bool {
                $companyIds = DataAccessService::placeableCompanyIds($user);

                return $companyIds !== null && in_array((int) $companyId, $companyIds, true);
            })
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Active users holding $role whose data access reaches $companyId (and $divisionId, for a division
     * rule), so a stage set to that role has someone to act on it. $excludedUserId (the requester of a
     * request, or both workers of a Tukar Shift) never counts: nobody holds a stage of their own request.
     * $alsoPlacement (the colleague's company and division on a Tukar Shift) must be reached as well.
     *
     * @param  int|string|list<int|string>|null  $excludedUserId
     * @param  array{company_id: int|string|null, division_id: int|string|null}|null  $alsoPlacement
     */
    public static function hasHolderForRole(string $role, int|string|null $companyId, int|string|null $divisionId = null, int|string|array|null $excludedUserId = null, ?array $alsoPlacement = null): bool
    {
        if ($companyId === null || $companyId === '') {
            return true;
        }

        // A role nobody created has no holders (User::role() would throw).
        if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
            return false;
        }

        // The platform Superadmin never staffs a client's stage.
        return User::role($role)
            ->where('is_active', true)
            ->when(filled($excludedUserId), fn ($query) => $query->whereKeyNot($excludedUserId))
            ->with('roles')
            ->get()
            ->contains(function (User $user) use ($companyId, $divisionId, $alsoPlacement): bool {
                if ($user->hasRole('Superadmin')) {
                    return false;
                }

                if ($alsoPlacement !== null && ! DataAccessService::canAccessPlacement($user, $alsoPlacement['company_id'], $alsoPlacement['division_id'])) {
                    return false;
                }

                if (filled($divisionId)) {
                    return DataAccessService::canAccessPlacement($user, $companyId, $divisionId);
                }

                $companyIds = DataAccessService::placeableCompanyIds($user);

                return $companyIds !== null && in_array((int) $companyId, $companyIds, true);
            });
    }

    /**
     * A stage naming this account has someone to act on it: an active account of the Klien
     * ($tenantId, the Klien of the company) that is not the platform Superadmin (who never staffs a
     * client's stage), nor the requester ($excludedUserId; both workers of a Tukar Shift). Same rule
     * isUserAuthorizedToApprove applies.
     *
     * @param  int|string|list<int|string>|null  $excludedUserId
     */
    private static function namedUserCanAct(int|string|null $userId, int|string|null $tenantId, int|string|array|null $excludedUserId = null): bool
    {
        $excludedUserIds = array_map('intval', array_filter(Arr::wrap($excludedUserId), 'filled'));

        if ($userId === null || in_array((int) $userId, $excludedUserIds, true)) {
            return false;
        }

        $user = User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'Superadmin'))
            ->first();

        return $user !== null && self::sameTenant($user, $tenantId);
    }

    /**
     * Whether the user belongs to the Klien $tenantId (no Klien on both sides counts as the same).
     */
    private static function sameTenant(User $user, int|string|null $tenantId): bool
    {
        return (int) $tenantId === (int) $user->tenant_id;
    }

    /**
     * Company-wide stages nobody can act on: a role without an active holder reaching the company,
     * or a named user who is inactive, gone or moved to another Klien. Requests reaching such a stage wait for an Admin of the
     * client, who is the only one left to act on it.
     *
     * @return list<array{request_type: string, stage: string, who: string}>
     */
    public static function unstaffedStages(Company $company): array
    {
        $unstaffed = [];

        foreach ($company->approvalFlows()->companyWide()->orderBy('request_type')->orderBy('step_order')->get() as $flow) {
            $staffed = $flow->approver_type === 'user'
                ? self::namedUserCanAct($flow->user_id, $company->tenant_id)
                : self::hasHolderForRole((string) ($flow->approver_role ?? 'Approver'), $company->id);

            if (! $staffed) {
                $unstaffed[] = [
                    'request_type' => $flow->request_type,
                    'stage' => $flow->name,
                    'who' => $flow->approver_type === 'user' ? 'user yang dipilih' : 'role '.($flow->approver_role ?? 'Approver'),
                ];
            }
        }

        return $unstaffed;
    }

    /**
     * Remind the people who can act on requests that have waited on their current stage for $days or more.
     * At most one reminder per request and stage a day. Returns how many requests were reminded about.
     */
    public function remindStalled(int $days = 2): int
    {
        $reminded = 0;

        foreach ([LeaveRequest::class, OvertimeRequest::class, AttendanceCorrection::class, Resignation::class, Attendance::class, ShiftSwapRequest::class] as $modelClass) {
            $modelClass::query()
                ->whereIn('status', self::PENDING_STATUSES)
                ->with(self::pendingEagerLoads($modelClass))
                ->get()
                ->each(function (Model $request) use ($days, &$reminded): void {
                    $stalledSince = $this->stalledSince($request);

                    if (! $stalledSince || $stalledSince->greaterThan(now()->subDays($days))) {
                        return;
                    }

                    $step = (int) ($request->current_step ?? 1);
                    $key = "approval-stale:{$request->getMorphClass()}:{$request->getKey()}:{$step}";

                    if (! Cache::add($key, true, now()->addDay())) {
                        return;
                    }

                    $type = $this->requestTypeOf($request);
                    $workerName = $this->workerOf($request)?->full_name ?? 'Pengguna';
                    $label = self::requestTypeLabel($type);
                    $waiting = (int) $stalledSince->diffInDays(now());

                    foreach ($this->getUsersAuthorizedForCurrentStep($request) as $approver) {
                        Notification::make()
                            ->title('Pengajuan Menunggu Persetujuan')
                            ->body("Pengajuan {$label} dari {$workerName} sudah menunggu {$waiting} hari.")
                            ->icon('heroicon-o-clock')
                            ->color('danger')
                            ->sendToDatabase($approver);

                        $approver->notify(new ApprovalRequestedNotification($request, $type, $workerName, $step));
                    }

                    $reminded++;
                });
        }

        return $reminded;
    }

    /**
     * When the request reached its current stage: the previous stage's approval, or the first stage's creation.
     */
    private function stalledSince(Model $request): ?Carbon
    {
        $current = (int) ($request->current_step ?? 1);
        $previous = $request->approvalSteps->where('step_order', '<', $current)->whereNotNull('actioned_at')->sortByDesc('step_order')->first();
        $since = $previous?->actioned_at ?? $request->approvalSteps->firstWhere('step_order', $current)?->created_at;

        return $since ? Carbon::parse($since) : null;
    }

    public static function requestTypeLabel(string $requestType): string
    {
        return self::REQUEST_TYPE_LABELS[$requestType] ?? $requestType;
    }

    /**
     * Relations every pending request needs to resolve its worker, stage and recipients without lazy loading.
     *
     * @param  class-string<Model>  $modelClass
     * @return list<string>
     */
    private static function pendingEagerLoads(string $modelClass): array
    {
        // A Tukar Shift also names the colleague: approval rights and data access depend on them too.
        return collect(['employee', 'intern', 'freelancer', 'counterpartEmployee', 'counterpartIntern', 'counterpartFreelancer'])
            ->filter(fn (string $relation): bool => method_exists($modelClass, $relation))
            ->flatMap(fn (string $relation): array => ["{$relation}.company", "{$relation}.division", "{$relation}.user"])
            ->push('approvalSteps')
            ->values()
            ->all();
    }

    /**
     * Reusable query method to retrieve pending requests requiring action from the given User.
     * Only the requests of the user's Klien are loaded (the Superadmin is unbounded); the stage and
     * data access rules are then checked per request.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function getPendingRequestsForUser(User $user, string $modelClass): Collection
    {
        if (! $user->hasAnyRole(self::INBOX_ROLES) && ! $this->isNamedOnPendingStep($user)) {
            return (new $modelClass)->newCollection();
        }

        return $modelClass::query()
            ->with(self::pendingEagerLoads($modelClass))
            ->whereIn('status', self::PENDING_STATUSES)
            // A Tukar Shift whose date passed can no longer be applied: it leaves the inbox at once, while
            // shift-swaps:expire saves it as expired.
            ->when(method_exists($modelClass, 'scopeNotOverdue'), fn ($query) => $query->notOverdue())
            ->when(
                ! $user->hasRole('Superadmin') && method_exists($modelClass, 'scopeWhereWorkerInCompanies'),
                fn ($query) => $query->whereWorkerInCompanies(DataAccessService::tenantCompanyIds($user)),
            )
            ->get()
            ->filter(fn (Model $item) => $this->isUserAuthorizedToApprove($item, $user))
            ->values();
    }

    /**
     * Whether a pending stage of any request names this user as its approver.
     */
    private function isNamedOnPendingStep(User $user): bool
    {
        return ApprovalRequestStep::query()
            ->where('approver_type', 'user')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Give a company without any approval flow the default: one Approver stage per request type, the
     * person responsible for the worker's division. Companies that need BOD or more stages add them
     * in Alur Persetujuan.
     */
    public function createDefaultFlows(Company $company): void
    {
        if ($company->approvalFlows()->companyWide()->exists()) {
            return;
        }

        foreach (self::REQUEST_TYPE_LABELS as $requestType => $label) {
            ApprovalFlow::create([
                'company_id' => $company->id,
                'request_type' => $requestType,
                'step_number' => 1,
                'step_order' => 1,
                'name' => "Persetujuan {$label}",
                'approver_type' => 'role',
                'approver_role' => 'Approver',
            ]);
        }
    }

    /**
     * Replace a division's own rule for the given request types with $steps, in the order given.
     *
     * @param  list<string>  $requestTypes
     * @param  list<array{name?: string|null, approver_type?: string|null, approver_role?: string|null, user_id?: int|string|null}>  $steps
     */
    public function saveDivisionRule(Company $company, int $divisionId, array $requestTypes, array $steps): void
    {
        $division = Division::query()->where('company_id', $company->id)->findOrFail($divisionId);
        $requestTypes = array_values(array_intersect($requestTypes, array_keys(self::REQUEST_TYPE_LABELS)));

        if ($requestTypes === [] || $steps === []) {
            throw ValidationException::withMessages(['steps' => 'Pilih jenis pengajuan dan isi minimal 1 tahap approval.']);
        }

        foreach ($steps as $index => $step) {
            $type = $step['approver_type'] ?? 'role';
            $role = $step['approver_role'] ?? null;

            if (($type === 'role' && ! in_array($role, self::APPROVER_ROLES, true))
                || ($type === 'user' && ! array_key_exists((int) ($step['user_id'] ?? 0), self::approverUserOptions($company->id)))) {
                throw ValidationException::withMessages(['steps' => 'Tahap '.($index + 1).' belum memilih approver, atau approver-nya tidak punya akses ke badan usaha ini.']);
            }
        }

        DB::transaction(function () use ($company, $division, $requestTypes, $steps): void {
            foreach ($requestTypes as $requestType) {
                ApprovalFlow::query()
                    ->where('company_id', $company->id)
                    ->where('division_id', $division->id)
                    ->where('request_type', $requestType)
                    ->delete();

                foreach ($steps as $index => $step) {
                    $isUser = ($step['approver_type'] ?? 'role') === 'user';

                    ApprovalFlow::create([
                        'company_id' => $company->id,
                        'division_id' => $division->id,
                        'request_type' => $requestType,
                        'step_number' => $index + 1,
                        'step_order' => $index + 1,
                        'name' => filled($step['name'] ?? null) ? $step['name'] : 'Tahap '.($index + 1),
                        'approver_type' => $isUser ? 'user' : 'role',
                        'approver_role' => $isUser ? null : $step['approver_role'],
                        'user_id' => $isUser ? (int) $step['user_id'] : null,
                    ]);
                }
            }
        });
    }

    /**
     * The flow that applies to a worker: the rule of their division, else of the nearest division
     * above it that has one, else the company-wide flow. Resolved per request type, so a division
     * can set its own leave rule and keep the company's flow for everything else.
     *
     * @return Collection<int, ApprovalFlow>
     */
    public function flowsFor(int $companyId, int|string|null $divisionId, string $requestType): Collection
    {
        $flows = ApprovalFlow::query()
            ->where('company_id', $companyId)
            ->where('request_type', $requestType)
            ->orderBy('step_order')
            ->get();

        foreach ($this->divisionLineage($divisionId) as $lineageDivisionId) {
            $divisionFlows = $flows->where('division_id', $lineageDivisionId);

            if ($divisionFlows->isNotEmpty()) {
                return $divisionFlows->values();
            }
        }

        return $flows->whereNull('division_id')->values();
    }

    /**
     * The division and the divisions above it, nearest first.
     *
     * @return list<int>
     */
    private function divisionLineage(int|string|null $divisionId): array
    {
        if (! $divisionId) {
            return [];
        }

        $parentIdsById = Division::query()->pluck('parent_id', 'id')->all();
        $lineage = [];
        $current = (int) $divisionId;

        while ($current && ! in_array($current, $lineage, true)) {
            $lineage[] = $current;
            $current = (int) ($parentIdsById[$current] ?? 0);
        }

        return $lineage;
    }

    /**
     * Generate approval steps for a request based on Company's configured approval flows.
     */
    public function generateSteps(Model $requestModel, string $requestType): array
    {
        if (method_exists($requestModel, 'employee') && ! $requestModel->relationLoaded('employee')) {
            $requestModel->load('employee');
        }

        if (method_exists($requestModel, 'intern') && ! $requestModel->relationLoaded('intern')) {
            $requestModel->load('intern');
        }

        if (method_exists($requestModel, 'freelancer') && ! $requestModel->relationLoaded('freelancer')) {
            $requestModel->load('freelancer');
        }

        $workerProfile = method_exists($requestModel, 'getWorkerProfile')
            ? $requestModel->getWorkerProfile()
            : ($requestModel->employee ?? $requestModel->intern ?? $requestModel->freelancer);

        // Never guess: another client's first company would route the request to the wrong people.
        $companyId = $workerProfile?->company_id
            ?? $requestModel->company_id;

        if (! $companyId) {
            throw ValidationException::withMessages([
                'company' => 'Badan Usaha (Company) tidak ditemukan pada data pengajuan.',
            ]);
        }

        $flows = $this->flowsFor((int) $companyId, $workerProfile?->division_id, $requestType);

        if ($flows->isEmpty()) {
            // Geofence and Tukar Shift requests are filed by the app on its own (a check-in, a colleague's
            // acceptance), and a company flow saved before these types existed may lack them: they fall back
            // to the default Approver stage instead of failing.
            if (in_array($requestType, ['geofence', 'shift_swap'], true)) {
                $flows = collect([
                    (object) [
                        'step_order' => 1,
                        'name' => 'Persetujuan '.self::requestTypeLabel($requestType),
                        'approver_type' => 'role',
                        'approver_role' => 'Approver',
                        'user_id' => null,
                    ],
                ]);
            } else {
                throw ValidationException::withMessages([
                    'approval_flow' => 'Alur approval untuk jenis pengajuan ini belum dikonfigurasi oleh Perusahaan.',
                ]);
            }
        }

        // A request that already went through a round (for example a geofence check-in that was
        // approved, then a check-out outside the geofence) keeps its history: the new steps continue
        // the numbering so the approver acts on the new round, not on an already actioned step.
        $stepOrderOffset = (int) $requestModel->approvalSteps()->max('step_order');

        $generatedSteps = [];

        foreach ($flows as $flow) {
            $step = ApprovalRequestStep::create([
                'approvable_type' => $requestModel->getMorphClass(),
                'approvable_id' => $requestModel->id,
                'step_order' => $stepOrderOffset + $flow->step_order,
                'step_name' => $flow->name,
                'approver_type' => $flow->approver_type,
                'approver_role' => $flow->approver_role,
                'user_id' => $flow->user_id,
                'status' => 'pending',
            ]);

            $generatedSteps[] = $step;
        }

        $requestModel->update([
            'current_step' => $stepOrderOffset + 1,
            'status' => $requestModel instanceof Attendance ? AttendanceStatus::PendingApproval : 'pending',
        ]);

        // Send In-App Notifications to Authorized Approvers
        $workerName = method_exists($requestModel, 'getWorkerProfile')
            ? ($requestModel->getWorkerProfile()?->full_name ?? 'Pengguna')
            : ($requestModel->employee?->full_name ?? 'Pengguna');

        foreach ($this->getUsersAuthorizedForCurrentStep($requestModel) as $approverUser) {
            Notification::make()
                ->title('Pengajuan Baru Membutuhkan Persetujuan Anda')
                ->body("Pengajuan {$requestType} dari {$workerName} menunggu persetujuan Anda.")
                ->icon('heroicon-o-bell')
                ->color('warning')
                ->sendToDatabase($approverUser);

            // Queued push: dispatched once the request is committed, so the job can load it and a
            // rolled back request never notifies anyone.
            DB::afterCommit(fn () => $approverUser->notify(new ApprovalRequestedNotification($requestModel, $requestType, $workerName, (int) $requestModel->current_step, isNewRequest: true)));
        }

        return $generatedSteps;
    }

    /**
     * Check if a User is authorized to approve the current active step of a request.
     * Parallel Approver Rule (OR semantics): If multiple approvers exist for a step,
     * ANY one authorized approver satisfies the step.
     */
    public function isUserAuthorizedToApprove(Model $requestModel, User $user): bool
    {
        if (! in_array(self::statusValueOf($requestModel), self::PENDING_STATUSES, true)) {
            return false;
        }

        $currentStepOrder = (int) ($requestModel->current_step ?? 1);

        // Lists (inbox, reminders) load the steps once; a single request falls back to a query.
        $loadedSteps = $requestModel->relationLoaded('approvalSteps') ? $requestModel->approvalSteps : null;

        $step = $loadedSteps
            ? $loadedSteps->first(fn (ApprovalRequestStep $candidate): bool => (int) $candidate->step_order === $currentStepOrder)
            : $requestModel->approvalSteps()->where('step_order', $currentStepOrder)->first();

        if (! $step) {
            return false;
        }

        // Neither worker of a Tukar Shift acts on any of its stages, not even through an override: the swap
        // rewrites both their schedules, so someone else must decide.
        if ($requestModel instanceof ShiftSwapRequest && ($requestModel->isRequestedBy($user) || $requestModel->isCounterpart($user))) {
            return false;
        }

        // Resolve the worker profile to get the requester and the company/division scope
        $workerProfile = $this->workerOf($requestModel);

        // A Tukar Shift also rewrites the colleague's schedule, possibly in another division or outlet: the
        // approver must reach both workers, so the colleague's own coordinator is never bypassed.
        $counterpart = $this->counterpartOf($requestModel);

        $isOwnRequest = $workerProfile?->user_id !== null && (int) $workerProfile->user_id === (int) $user->id;

        // A requester may act on an intermediate stage that matches their own role (for example a BOD
        // at the BOD stage), but never on the final stage: someone else must give the last approval.
        if ($isOwnRequest) {
            $hasLaterStep = $loadedSteps
                ? $loadedSteps->contains(fn (ApprovalRequestStep $candidate): bool => (int) $candidate->step_order > $currentStepOrder)
                : $requestModel->approvalSteps()->where('step_order', '>', $currentStepOrder)->exists();

            if (! $hasLaterStep) {
                return false;
            }
        }

        // Superadmin Override: the platform owner may still step in on any pending step (support), except
        // on their own request. They are never notified and never staff a stage.
        if ($user->hasRole('Superadmin')) {
            return ! $isOwnRequest;
        }

        // Admin and Koordinator Override: same authority, limited to the workers inside their data access
        if ($workerProfile && $user->hasAnyRole(['Admin', 'Koordinator']) && $user->canManageWorker($workerProfile)
            && ($counterpart === null || $user->canManageWorker($counterpart))) {
            return ! $isOwnRequest;
        }

        // 1. Direct User Assignment
        // Only while the named account is still active and still belongs to the Klien of the requester:
        // once either moves to another Klien, the old approver can no longer act on the request.
        if ($step->approver_type === 'user') {
            return (int) $user->id === (int) $step->user_id
                && $user->is_active !== false
                && $workerProfile !== null
                && $this->workerInTenantOf($user, $workerProfile);
        }

        // 2. Role + data access: the step's role, and the requester inside the approver's data access
        if ($step->approver_type === 'role') {
            $requiredRole = $step->approver_role ?? 'Approver';

            if (! $user->hasRole($requiredRole) || ! $workerProfile) {
                return false;
            }

            return DataAccessService::canAccessPlacement($user, $workerProfile->company_id, $workerProfile->division_id)
                && ($counterpart === null || DataAccessService::canAccessPlacement($user, $counterpart->company_id, $counterpart->division_id));
        }

        return false;
    }

    /**
     * Approve the current step for a request.
     *
     * Runs in a transaction on a locked, freshly read copy of the request, so two approvers acting at
     * the same time (or on a page opened before the other one acted) can never both settle the same
     * stage. Notifications go out once the transaction has committed.
     *
     * @throws ValidationException when the user may not act, or the request was already processed
     */
    public function approveStep(Model $requestModel, User $user, ?string $notes = null): void
    {
        $shiftSwapFailure = null;

        DB::transaction(function () use ($requestModel, $user, $notes, &$shiftSwapFailure): void {
            $request = $this->lockForProcessing($requestModel);

            if (! $this->isUserAuthorizedToApprove($request, $user)) {
                throw ValidationException::withMessages([
                    'approve' => 'Anda tidak memiliki hak akses untuk menyetujui tahap approval ini.',
                ]);
            }

            $currentStepOrder = (int) ($request->current_step ?? 1);
            $maxSteps = (int) $request->approvalSteps()->max('step_order');
            $closesAllStages = $this->closesAllStages($user);
            $isFinalApproval = $closesAllStages || $currentStepOrder >= $maxSteps;

            if ($request instanceof LeaveRequest && $isFinalApproval) {
                $this->assertLeaveCanBeApproved($request);
            }

            // A Tukar Shift is only approved when it can be applied: the schedules are re-checked under row
            // locks and swapped here. One that can no longer be applied ends as failed (or expired) instead of
            // waiting for an approval that can never succeed; the approver is told why once that is saved.
            if ($request instanceof ShiftSwapRequest && $isFinalApproval && $failure = app(ShiftSwapService::class)->applyApproved($request, $user)) {
                $request->update(['status' => $failure['status'], 'failure_reason' => $failure['message']]);
                $shiftSwapFailure = $failure['message'];

                // Nobody decided against it: the open stages are closed by the system with the reason, so the
                // history does not show them pending forever, and both workers hear it failed (not rejected).
                $request->approvalSteps()->where('status', 'pending')->update([
                    'status' => 'rejected',
                    'actioned_by' => null,
                    'actioned_at' => now(),
                    'notes' => "Gagal diterapkan: {$failure['message']}",
                ]);

                app(ShiftSwapService::class)->notifyNotApplied($request, $failure['status'], $failure['message']);
                $this->syncProcessedModel($requestModel, $request);

                return;
            }

            $request->approvalSteps()
                ->where(fn ($steps) => $steps->where('step_order', $currentStepOrder)
                    ->when($closesAllStages, fn ($query) => $query->orWhere(fn ($later) => $later->where('step_order', '>', $currentStepOrder)->where('status', 'pending'))))
                ->get()
                ->each(fn ($step) => $step->update([
                    'status' => 'approved',
                    'actioned_by' => $user->id,
                    'actioned_at' => now(),
                    'notes' => $notes,
                ]));

            if ($closesAllStages && $currentStepOrder < $maxSteps) {
                $request->update(['current_step' => $maxSteps]);
            }

            if (! $isFinalApproval) {
                $request->update([
                    'current_step' => $currentStepOrder + 1,
                ]);

                DB::afterCommit(fn () => $this->notifyNextApprovers($request));
            } else {
                if (method_exists($request, 'applyGeofenceApproval')) {
                    $request->applyGeofenceApproval();
                } else {
                    $request->update([
                        'status' => 'approved',
                    ]);
                }

                if (method_exists($request, 'applyCorrection')) {
                    $request->applyCorrection();
                }

                if (method_exists($request, 'applyLeave')) {
                    $request->applyLeave();
                }

                // Re-checked with this approval written: a concurrent approval that slipped past the
                // first check (or ran inside this transaction) rolls the whole approval back.
                if ($request instanceof LeaveRequest && $error = app(AnnualLeaveService::class)->approvalError($request, lockRows: true)) {
                    throw ValidationException::withMessages(['approve' => $error]);
                }

                if (method_exists($request, 'applyResignation')) {
                    $request->applyResignation();
                }

                DB::afterCommit(fn () => $this->notifyRequester($request, 'approved'));
            }

            $this->syncProcessedModel($requestModel, $request);
        });

        if ($shiftSwapFailure !== null) {
            throw ValidationException::withMessages(['approve' => $shiftSwapFailure]);
        }
    }

    /**
     * Checks made before the final approval of a leave, under a lock on the worker's profile row so
     * final approvals of the same worker's leave run one at a time and each sees the leave the other
     * just approved:
     *
     * - annual leave: the worker must still be entitled, the request may not overlap another pending
     *   or approved leave, and it must fit the quota of each year it touches;
     * - any leave: no leave day may already hold a check-in (any segment, also one waiting for
     *   geofence approval). The approver rejects the leave, or the worker changes the dates.
     *
     * Must be called inside a transaction.
     *
     * @throws ValidationException
     */
    private function assertLeaveCanBeApproved(LeaveRequest $leave): void
    {
        $profile = $leave->getWorkerProfile();

        if ($profile) {
            $profile->newQuery()->withoutGlobalScopes()->whereKey($profile->getKey())->lockForUpdate()->first();
        }

        if ($error = app(AnnualLeaveService::class)->approvalError($leave, lockRows: true)) {
            throw ValidationException::withMessages(['approve' => $error]);
        }

        $checkedInDates = $leave->checkedInDates(lockRows: true);

        if ($checkedInDates !== []) {
            $name = $profile?->full_name ?? 'Pekerja ini';
            $dates = collect($checkedInDates)->map(fn (string $date): string => Carbon::parse($date)->format('d/m/Y'))->implode(', ');

            throw ValidationException::withMessages([
                'approve' => "{$name} sudah melakukan presensi pada tanggal {$dates}. Tolak pengajuan ini, atau minta pekerja mengubah tanggal cuti/izinnya.",
            ]);
        }
    }

    /**
     * Tell the approvers of the stage the request just moved to.
     */
    private function notifyNextApprovers(Model $requestModel): void
    {
        $workerName = $this->workerOf($requestModel)?->full_name ?? 'Pengguna';
        $requestType = $this->requestTypeOf($requestModel);

        foreach ($this->getUsersAuthorizedForCurrentStep($requestModel) as $nextApprover) {
            Notification::make()
                ->title("Pengajuan Membutuhkan Persetujuan Tahap {$requestModel->current_step}")
                ->body("Pengajuan dari {$workerName} telah diproses ke tahap Anda.")
                ->icon('heroicon-o-bell')
                ->color('info')
                ->sendToDatabase($nextApprover);

            $nextApprover->notify(new ApprovalRequestedNotification($requestModel, $requestType, $workerName, (int) $requestModel->current_step));
        }
    }

    /**
     * Re-read the request with a row lock and make sure it is still where the approver saw it: pending,
     * on the same stage, and that stage not actioned yet. The returned copy keeps the worker relations
     * already loaded on $requestModel, but always reads its steps from the database.
     *
     * Must be called inside a transaction.
     *
     * @throws ValidationException when the request changed in the meantime
     */
    private function lockForProcessing(Model $requestModel): Model
    {
        $seenStatus = self::statusValueOf($requestModel);
        $seenStepOrder = (int) ($requestModel->current_step ?? 1);

        $request = $requestModel->newQuery()->lockForUpdate()->find($requestModel->getKey());

        $isUnchanged = $request !== null
            && in_array($seenStatus, self::PENDING_STATUSES, true)
            && self::statusValueOf($request) === $seenStatus
            && (int) ($request->current_step ?? 1) === $seenStepOrder
            && $request->approvalSteps()
                ->where('step_order', $seenStepOrder)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->exists();

        if (! $isUnchanged) {
            throw ValidationException::withMessages(['request' => self::ALREADY_PROCESSED_MESSAGE]);
        }

        $request->setRelations(Arr::except($requestModel->getRelations(), ['approvalSteps']));

        return $request;
    }

    /**
     * Bring the caller's instance up to date with the processed copy, so it does not keep showing the
     * request as it was before the approval.
     */
    private function syncProcessedModel(Model $requestModel, Model $processed): void
    {
        $requestModel->setRawAttributes($processed->getAttributes(), true);
        $requestModel->unsetRelation('approvalSteps');
    }

    private static function statusValueOf(Model $requestModel): string
    {
        return $requestModel->status instanceof \BackedEnum ? (string) $requestModel->status->value : (string) $requestModel->status;
    }

    /**
     * Whether the requester can still change or cancel the request: it is pending and no approver has
     * acted on any of its stages yet.
     */
    public static function isUntouchedByApprovers(Model $requestModel): bool
    {
        return self::statusValueOf($requestModel) === 'pending'
            && ! $requestModel->approvalSteps()
                ->where(fn ($steps) => $steps->where('status', '!=', 'pending')->orWhereNotNull('actioned_at'))
                ->exists();
    }

    /**
     * A coordinator decides for the crew of their brands, so one approval from them settles every
     * remaining stage instead of one tap per stage. Admin and Superadmin keep acting per stage.
     */
    private function closesAllStages(User $user): bool
    {
        return $user->hasRole('Koordinator') && ! $user->hasAnyRole(['Superadmin', 'Admin']);
    }

    /**
     * Reject the current step for a request.
     *
     * Locked like approveStep(), so a request another approver just approved (or moved on) cannot be
     * rejected from a stale page.
     *
     * @throws ValidationException when the user may not act, or the request was already processed
     */
    public function rejectStep(Model $requestModel, User $user, string $reason): void
    {
        DB::transaction(function () use ($requestModel, $user, $reason): void {
            $request = $this->lockForProcessing($requestModel);

            if (! $this->isUserAuthorizedToApprove($request, $user)) {
                throw ValidationException::withMessages([
                    'reject' => 'Anda tidak memiliki hak akses untuk menolak tahap approval ini.',
                ]);
            }

            $request->approvalSteps()
                ->where('step_order', (int) ($request->current_step ?? 1))
                ->first()
                ?->update([
                    'status' => 'rejected',
                    'actioned_by' => $user->id,
                    'actioned_at' => now(),
                    'notes' => $reason,
                ]);

            if (method_exists($request, 'applyGeofenceRejection')) {
                $request->applyGeofenceRejection($reason);
            } else {
                $request->update([
                    'status' => 'rejected',
                    'rejection_reason' => $reason,
                ]);
            }

            DB::afterCommit(fn () => $this->notifyRequester($request, 'rejected', $reason));

            if ($request instanceof ShiftSwapRequest) {
                app(ShiftSwapService::class)->notifyRejectedByApprover($request, $reason);
            }

            $this->syncProcessedModel($requestModel, $request);
        });
    }

    /**
     * Push the final outcome to the account of the worker who submitted the request.
     */
    private function notifyRequester(Model $requestModel, string $status, ?string $reason = null): void
    {
        $workerProfile = method_exists($requestModel, 'getWorkerProfile')
            ? $requestModel->getWorkerProfile()
            : ($requestModel->employee ?? $requestModel->intern ?? $requestModel->freelancer);

        $requester = $workerProfile?->user;

        if (! $requester instanceof User) {
            return;
        }

        $requester->notify(new ApprovalResolvedNotification($requestModel, $this->requestTypeOf($requestModel), $status, $reason));
    }

    /**
     * The request type key (as used by approval flows and the mobile app) of a request model.
     */
    public function requestTypeOf(Model $requestModel): string
    {
        return match (true) {
            $requestModel instanceof LeaveRequest => 'leave',
            $requestModel instanceof OvertimeRequest => 'overtime',
            $requestModel instanceof AttendanceCorrection => 'correction',
            $requestModel instanceof Resignation => 'resignation',
            $requestModel instanceof Attendance => 'geofence',
            $requestModel instanceof ShiftSwapRequest => 'shift_swap',
            default => 'request',
        };
    }

    /**
     * Get users who are eligible to act on the request's active approval step.
     */
    private function getUsersAuthorizedForCurrentStep(Model $requestModel): Collection
    {
        $step = $requestModel->approvalSteps()->where('step_order', (int) ($requestModel->current_step ?? 1))->first();

        // Coordinators decide for the crew of their brands, so they hear about every request there.
        // Admins act on any stage too, but only hear about the stages set to them, to keep the noise down.
        $roles = ['Approver', 'BOD', 'Koordinator'];

        if ($step?->approver_type === 'role' && filled($step->approver_role)) {
            $roles[] = $step->approver_role;
        }

        // The platform Superadmin can act on anything, but is never notified about a client's requests.
        $withoutPlatform = fn (Collection $users): Collection => $users->reject(fn (User $user): bool => $user->hasRole('Superadmin'))->values();

        $recipients = $withoutPlatform($this->usersWithRoles($requestModel, $step, $roles));

        // A stage nobody holds, with no coordinator covering the worker, would reach no one: the Admins
        // of the company hear about it as well.
        if ($step && $recipients->isEmpty() && ! $this->stageHasHolder($requestModel, $step)) {
            $recipients = $withoutPlatform($this->usersWithRoles($requestModel, $step, [...$roles, 'Admin']));
        }

        return $recipients;
    }

    /**
     * @param  list<string>  $roles
     * @return Collection<int, User>
     */
    private function usersWithRoles(Model $requestModel, ?ApprovalRequestStep $step, array $roles): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', array_unique($roles)))
                ->when($step?->approver_type === 'user' && $step->user_id, fn ($byUser) => $byUser->orWhere('id', $step->user_id)))
            ->with('roles')
            ->get()
            ->filter(fn (User $user) => $this->isUserAuthorizedToApprove($requestModel, $user));
    }

    /**
     * Whether the worker's company belongs to the user's Klien (no Klien on both sides counts as the same).
     */
    private function workerInTenantOf(User $user, Model $workerProfile): bool
    {
        return self::sameTenant($user, $this->tenantIdOfWorker($workerProfile));
    }

    /**
     * The Klien of the worker's company.
     */
    private function tenantIdOfWorker(Model $workerProfile): int|string|null
    {
        return $workerProfile->relationLoaded('company')
            ? $workerProfile->company?->tenant_id
            : Company::query()->whereKey($workerProfile->company_id)->value('tenant_id');
    }

    private function workerOf(Model $requestModel): ?Model
    {
        return method_exists($requestModel, 'getWorkerProfile')
            ? $requestModel->getWorkerProfile()
            : ($requestModel->employee ?? $requestModel->intern ?? $requestModel->freelancer);
    }

    /**
     * Whether someone other than the requester can act on the stage: the requester never holds a stage
     * of their own request (and cannot give its final approval), so a BOD's own leave at the BOD stage
     * falls back to the Admins when that BOD is the only one.
     */
    private function stageHasHolder(Model $requestModel, ApprovalRequestStep $step): bool
    {
        $worker = $this->workerOf($requestModel);
        $counterpart = $this->counterpartOf($requestModel);
        $excludedUserIds = array_values(array_filter([$worker?->user_id, $counterpart?->user_id]));
        $alsoPlacement = $counterpart ? ['company_id' => $counterpart->company_id, 'division_id' => $counterpart->division_id] : null;

        if ($step->approver_type === 'user') {
            return $worker !== null && self::namedUserCanAct($step->user_id, $this->tenantIdOfWorker($worker), $excludedUserIds);
        }

        return $worker === null || self::hasHolderForRole((string) ($step->approver_role ?? 'Approver'), $worker->company_id, $worker->division_id, $excludedUserIds, $alsoPlacement);
    }

    /**
     * For a Tukar Shift, the colleague whose schedule changes as well; null for every other request.
     */
    private function counterpartOf(Model $requestModel): ?Model
    {
        return $requestModel instanceof ShiftSwapRequest ? $requestModel->counterpartProfile() : null;
    }
}
