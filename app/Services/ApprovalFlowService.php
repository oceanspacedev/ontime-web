<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequestStep;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Resignation;
use App\Models\User;
use App\Notifications\ApprovalRequestedNotification;
use App\Notifications\ApprovalResolvedNotification;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
     * Active users holding $role whose data access reaches $companyId, so a stage set to that role
     * has someone to act on it.
     */
    public static function hasHolderForRole(string $role, int|string|null $companyId): bool
    {
        if ($companyId === null || $companyId === '') {
            return true;
        }

        return User::role($role)->where('is_active', true)->get()->contains(function (User $user) use ($companyId): bool {
            $companyIds = DataAccessService::placeableCompanyIds($user);

            return $companyIds === null || in_array((int) $companyId, $companyIds, true);
        });
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
     * Give a company without any approval flow the default stages: Approver, BOD, then Superadmin
     * for leave, overtime, correction and resignation, and a single Approver stage for geofence.
     */
    public function createDefaultFlows(Company $company): void
    {
        if ($company->approvalFlows()->exists()) {
            return;
        }

        $labels = collect(self::REQUEST_TYPE_LABELS)->except('geofence')->all();
        $stages = [['Approver', 'Persetujuan Approver'], ['BOD', 'Persetujuan BOD'], ['Superadmin', 'Persetujuan Akhir Superadmin']];

        foreach ($labels as $requestType => $label) {
            foreach ($stages as $index => [$role, $stageName]) {
                ApprovalFlow::create([
                    'company_id' => $company->id,
                    'request_type' => $requestType,
                    'step_number' => $index + 1,
                    'step_order' => $index + 1,
                    'name' => "{$stageName} {$label}",
                    'approver_type' => 'role',
                    'approver_role' => $role,
                ]);
            }
        }

        ApprovalFlow::create([
            'company_id' => $company->id,
            'request_type' => 'geofence',
            'step_number' => 1,
            'step_order' => 1,
            'name' => 'Persetujuan Presensi Luar Geofence',
            'approver_type' => 'role',
            'approver_role' => 'Approver',
        ]);
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

        $flows = ApprovalFlow::where('company_id', $companyId)
            ->where('request_type', $requestType)
            ->orderBy('step_order')
            ->get();

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

        return User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', array_unique($roles)))
                ->when($step?->approver_type === 'user' && $step->user_id, fn ($byUser) => $byUser->orWhere('id', $step->user_id)))
            ->get()
            ->filter(fn (User $user) => $this->isUserAuthorizedToApprove($requestModel, $user));
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
