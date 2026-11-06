<?php

use App\Models\ApprovalFlow;
use App\Services\ApprovalFlowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;

/**
 * The default approval flow is now a single Approver stage. Request types still on the old default
 * (Approver, BOD, Superadmin) are trimmed to that; flows a company customised are left alone, and
 * requests already in progress keep the stages they were created with.
 */
return new class extends Migration
{
    public function up(): void
    {
        ApprovalFlow::query()
            ->whereIn('request_type', ['leave', 'overtime', 'correction', 'resignation'])
            ->orderBy('step_order')
            ->get()
            ->groupBy(fn (ApprovalFlow $flow): string => "{$flow->company_id}|{$flow->request_type}")
            ->filter(fn (Collection $flows): bool => $this->isOldDefault($flows))
            ->each(function (Collection $flows): void {
                $first = $flows->first();
                ApprovalFlow::whereKey($flows->skip(1)->modelKeys())->delete();
                $first->update(['name' => 'Persetujuan '.ApprovalFlowService::requestTypeLabel($first->request_type)]);
            });
    }

    public function down(): void
    {
        // The trimmed stages are not restored; add them again in Alur Persetujuan if needed.
    }

    /**
     * @param  Collection<int, ApprovalFlow>  $flows
     */
    private function isOldDefault(Collection $flows): bool
    {
        return $flows->count() === 3
            && $flows->every(fn (ApprovalFlow $flow): bool => $flow->approver_type === 'role')
            && $flows->pluck('approver_role')->values()->all() === ['Approver', 'BOD', 'Superadmin'];
    }
};
