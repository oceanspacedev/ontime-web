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
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalResolvedNotification;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
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
    ];

    /**
     * Roles that can act on an approval stage; a stage set to any other role could never be approved.
     *
     * @var list<string>
     */
    public const APPROVER_ROLES = ['Approver', 'BOD', 'Superadmin', 'Admin', 'Koordinator'];

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
            ->orderBy('name')
            ->get()
            ->filter(function (User $user) use ($companyId): bool {
                $companyIds = DataAccessService::placeableCompanyIds($user);

                return $companyIds === null || in_array((int) $companyId, $companyIds, true);
            })
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Active users holding $role whose data access reaches $companyId (and $divisionId, for a division
     * rule), so a stage set to that role
     * has someone to act on it.
     */
    public static function hasHolderForRole(string $role, int|string|null $companyId, int|string|null $divisionId = null): bool
    {
        if ($companyId === null || $companyId === '') {
            return true;
        }

        // A role nobody created has no holders (User::role() would throw).
        if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
            return false;
        }

        return User::role($role)->where('is_active', true)->get()->contains(function (User $user) use ($companyId, $divisionId): bool {
            if (filled($divisionId)) {
                return DataAccessService::canAccessPlacement($user, $companyId, $divisionId);
            }

            $companyIds = DataAccessService::placeableCompanyIds($user);

            return $companyIds === null || in_array((int) $companyId, $companyIds, true);
        });
    }

    /**
     * Company-wide stages nobody can act on: a role without an active holder reaching the company,
     * or a named user who is inactive or gone. Requests reaching such a stage wait for an Admin or
     * Superadmin, who are the only ones left to act on it.
     *
     * @return list<array{request_type: string, stage: string, who: string}>
     */
    public static function unstaffedStages(Company $company): array
    {
        $unstaffed = [];

        foreach ($company->approvalFlows()->companyWide()->orderBy('request_type')->orderBy('step_order')->get() as $flow) {
            $staffed = $flow->approver_type === 'user'
                ? $flow->user_id !== null && User::query()->whereKey($flow->user_id)->where('is_active', true)->exists()
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

        foreach ([LeaveRequest::class, OvertimeRequest::class, AttendanceCorrection::class, Resignation::class, Attendance::class] as $modelClass) {
            $modelClass::query()
                ->whereIn('status', ['pending', 'pending_approval'])
                ->with('approvalSteps')
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
     * Reusable query method to retrieve pending requests requiring action from the given User.
     */
    public function getPendingRequestsForUser(User $user, string $modelClass): Collection
    {
        $eagerLoads = collect(['employee', 'intern', 'freelancer'])
            ->filter(fn (string $relation): bool => method_exists($modelClass, $relation))
            ->flatMap(fn (string $relation): array => ["{$relation}.company", "{$relation}.division", "{$relation}.user"])
            ->push('approvalSteps')
            ->all();

        return $modelClass::query()
            ->with($eagerLoads)
            ->whereIn('status', ['pending', 'pending_approval'])
            ->get()
            ->filter(fn (Model $item) => $this->isUserAuthorizedToApprove($item, $user));
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

        $companyId = $workerProfile?->company_id
            ?? $requestModel->company_id
            ?? Company::first()?->id;

        if (! $companyId) {
            throw ValidationException::withMessages([
                'company' => 'Badan Usaha (Company) tidak ditemukan pada data pengajuan.',
            ]);
        }

        $flows = $this->flowsFor((int) $companyId, $workerProfile?->division_id, $requestType);

        if ($flows->isEmpty()) {
            if ($requestType === 'geofence') {
                $flows = collect([
                    (object) [
                        'step_order' => 1,
                        'name' => 'Persetujuan Presensi Luar Geofence',
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

            $approverUser->notify(new ApprovalRequestedNotification($requestModel, $requestType, $workerName, (int) $requestModel->current_step, isNewRequest: true));
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
        $statusValue = $requestModel->status instanceof \BackedEnum ? $requestModel->status->value : (string) $requestModel->status;
        if (! in_array($statusValue, ['pending', 'pending_approval'])) {
            return false;
        }

        $currentStepOrder = (int) ($requestModel->current_step ?? 1);
        $step = $requestModel->approvalSteps()
            ->where('step_order', $currentStepOrder)
            ->first();

        if (! $step) {
            return false;
        }

        // Resolve the worker profile to get the requester and the company/division scope
        $workerProfile = method_exists($requestModel, 'getWorkerProfile')
            ? $requestModel->getWorkerProfile()
            : ($requestModel->employee ?? $requestModel->intern ?? $requestModel->freelancer);

        $isOwnRequest = $workerProfile?->user_id !== null && (int) $workerProfile->user_id === (int) $user->id;

        // A requester may act on an intermediate stage that matches their own role (for example a BOD
        // at the BOD stage), but never on the final stage: someone else must give the last approval.
        if ($isOwnRequest && ! $requestModel->approvalSteps()->where('step_order', '>', $currentStepOrder)->exists()) {
            return false;
        }

        // Superadmin Override: Superadmin may act on any pending step, except on their own request
        if ($user->hasRole('Superadmin')) {
            return ! $isOwnRequest;
        }

        // Admin and Koordinator Override: same authority, limited to the workers inside their data access
        if ($workerProfile && $user->hasAnyRole(['Admin', 'Koordinator']) && $user->canManageWorker($workerProfile)) {
            return ! $isOwnRequest;
        }

        // 1. Direct User Assignment
        if ($step->approver_type === 'user') {
            return (int) $user->id === (int) $step->user_id;
        }

        // 2. Role + data access: the step's role, and the requester inside the approver's data access
        if ($step->approver_type === 'role') {
            $requiredRole = $step->approver_role ?? 'Approver';

            if (! $user->hasRole($requiredRole) || ! $workerProfile) {
                return false;
            }

            return DataAccessService::canAccessPlacement($user, $workerProfile->company_id, $workerProfile->division_id);
        }

        return false;
    }

    /**
     * Approve the current step for a request.
     */
    public function approveStep(Model $requestModel, User $user, ?string $notes = null): void
    {
        if (! $this->isUserAuthorizedToApprove($requestModel, $user)) {
            throw ValidationException::withMessages([
                'approve' => 'Anda tidak memiliki hak akses untuk menyetujui tahap approval ini.',
            ]);
        }

        $currentStepOrder = (int) ($requestModel->current_step ?? 1);
        $maxSteps = (int) $requestModel->approvalSteps()->max('step_order');
        $closesAllStages = $this->closesAllStages($user);
        $isFinalApproval = $closesAllStages || $currentStepOrder >= $maxSteps;

        // The final approval turns annual leave into used quota, so the worker must still be entitled.
        if ($requestModel instanceof LeaveRequest && $isFinalApproval
            && $error = app(AnnualLeaveService::class)->approvalError($requestModel)) {
            throw ValidationException::withMessages(['approve' => $error]);
        }

        $requestModel->approvalSteps()
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
            $requestModel->update(['current_step' => $maxSteps]);
        }

        if (! $isFinalApproval) {
            $requestModel->update([
                'current_step' => $currentStepOrder + 1,
            ]);

            // Notify next step approvers
            $workerNameNext = method_exists($requestModel, 'getWorkerProfile')
                ? ($requestModel->getWorkerProfile()?->full_name ?? 'Pengguna')
                : ($requestModel->employee?->full_name ?? 'Pengguna');

            $requestType = $this->requestTypeOf($requestModel);

            foreach ($this->getUsersAuthorizedForCurrentStep($requestModel) as $nextApprover) {
                Notification::make()
                    ->title("Pengajuan Membutuhkan Persetujuan Tahap {$requestModel->current_step}")
                    ->body("Pengajuan dari {$workerNameNext} telah diproses ke tahap Anda.")
                    ->icon('heroicon-o-bell')
                    ->color('info')
                    ->sendToDatabase($nextApprover);

                $nextApprover->notify(new ApprovalRequestedNotification($requestModel, $requestType, $workerNameNext, (int) $requestModel->current_step));
            }
        } else {
            if (method_exists($requestModel, 'applyGeofenceApproval')) {
                $requestModel->applyGeofenceApproval();
            } else {
                $requestModel->update([
                    'status' => 'approved',
                ]);
            }

            if (method_exists($requestModel, 'applyCorrection')) {
                $requestModel->applyCorrection();
            }

            if (method_exists($requestModel, 'applyLeave')) {
                $requestModel->applyLeave();
            }

            if (method_exists($requestModel, 'applyResignation')) {
                $requestModel->applyResignation();
            }

            $this->notifyRequester($requestModel, 'approved');
        }
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
     */
    public function rejectStep(Model $requestModel, User $user, string $reason): void
    {
        if (! $this->isUserAuthorizedToApprove($requestModel, $user)) {
            throw ValidationException::withMessages([
                'reject' => 'Anda tidak memiliki hak akses untuk menolak tahap approval ini.',
            ]);
        }

        $currentStepOrder = (int) ($requestModel->current_step ?? 1);
        $step = $requestModel->approvalSteps()
            ->where('step_order', $currentStepOrder)
            ->first();

        if ($step) {
            $step->update([
                'status' => 'rejected',
                'actioned_by' => $user->id,
                'actioned_at' => now(),
                'notes' => $reason,
            ]);
        }

        if (method_exists($requestModel, 'applyGeofenceRejection')) {
            $requestModel->applyGeofenceRejection($reason);
        } else {
            $requestModel->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);
        }

        $this->notifyRequester($requestModel, 'rejected', $reason);
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
        $roles = ['Approver', 'BOD', 'Superadmin', 'Koordinator'];

        if ($step?->approver_type === 'role' && filled($step->approver_role)) {
            $roles[] = $step->approver_role;
        }

        $recipients = $this->usersWithRoles($requestModel, $step, $roles);

        // A stage nobody holds, with no coordinator covering the worker, would only reach Superadmins:
        // the Admins of the company hear about it as well.
        if ($step && $recipients->reject(fn (User $user): bool => $user->hasRole('Superadmin'))->isEmpty() && ! $this->stageHasHolder($requestModel, $step)) {
            $recipients = $this->usersWithRoles($requestModel, $step, [...$roles, 'Admin']);
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
            ->get()
            ->filter(fn (User $user) => $this->isUserAuthorizedToApprove($requestModel, $user));
    }

    private function workerOf(Model $requestModel): ?Model
    {
        return method_exists($requestModel, 'getWorkerProfile')
            ? $requestModel->getWorkerProfile()
            : ($requestModel->employee ?? $requestModel->intern ?? $requestModel->freelancer);
    }

    private function stageHasHolder(Model $requestModel, ApprovalRequestStep $step): bool
    {
        if ($step->approver_type === 'user') {
            return $step->user_id !== null && User::query()->whereKey($step->user_id)->where('is_active', true)->exists();
        }

        $worker = $this->workerOf($requestModel);

        return $worker === null || self::hasHolderForRole((string) ($step->approver_role ?? 'Approver'), $worker->company_id, $worker->division_id);
    }

    /**
     * Determine whether the configured flow uses the mandatory approval sequence.
     *
     * @param  Collection<int, ApprovalFlow>  $flows
     */
    private function isStandardApprovalFlow(Collection $flows): bool
    {
        $expectedRoles = ['Approver', 'BOD', 'Superadmin'];

        return $flows->count() === 3
            && $flows->values()->every(fn (ApprovalFlow $flow, int $index): bool => $flow->step_order === $index + 1
                && $flow->approver_type === 'role'
                && $flow->approver_role === $expectedRoles[$index]
            );
    }
}
