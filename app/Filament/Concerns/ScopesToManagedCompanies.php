<?php

namespace App\Filament\Concerns;

use App\Enums\SystemRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Shared rules for resources managed by Superadmin (every company) and Admin Badan Usaha
 * (only the companies in their company access).
 */
trait ScopesToManagedCompanies
{
    /**
     * Superadmin, or an Admin Badan Usaha with at least one company.
     */
    public static function canManageCompanyData(): bool
    {
        return (bool) auth()->user()?->canManageCompanies();
    }

    /**
     * Limit a query to the companies the current user manages.
     */
    public static function scopeToManagedCompanies(Builder $query, ?string $column = null): Builder
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $managed = $user->managedCompanyIds();

        if ($managed === null) {
            return $query;
        }

        return $managed === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column ?? $query->qualifyColumn('company_id'), $managed);
    }

    /**
     * Superadmin manages every worker record; Admin Badan Usaha only records of workers in their companies.
     */
    public static function canManageWorkerRecord(Model $record): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Superadmin')) {
            return true;
        }

        $worker = $record->employee ?? $record->intern ?? $record->freelancer;

        return $user->isCompanyAdmin() && $worker && $user->canManageCompany($worker->company_id);
    }

    /**
     * Company select options limited to the companies the current user manages.
     *
     * @return array<int, string>
     */
    public static function managedCompanyOptions(bool $activeOnly = true): array
    {
        return static::scopeToManagedCompanies(Company::query(), 'id')
            ->when($activeOnly, fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Roles the current user may hand out. Only Superadmin can grant Superadmin or Admin.
     *
     * @return array<string, string>
     */
    public static function assignableRoleOptions(): array
    {
        return Role::query()
            ->when(! auth()->user()?->hasRole('Superadmin'), fn (Builder $query) => $query->whereNotIn('name', [SystemRole::Superadmin->value, SystemRole::Admin->value]))
            ->orderBy('name')
            ->pluck('name', 'name')
            ->all();
    }

    /**
     * Whether the current user may delete or take over this login account. Nobody removes their own
     * account here, and Admin Badan Usaha never touches Superadmin or Admin accounts.
     */
    public static function canManageAccount(?User $account): bool
    {
        $user = auth()->user();

        if (! $user || ! $account || $account->is($user)) {
            return false;
        }

        if ($user->hasRole('Superadmin')) {
            return true;
        }

        return $user->isCompanyAdmin() && ! $account->hasAnyRole([SystemRole::Superadmin->value, SystemRole::Admin->value]);
    }

    /**
     * Accounts the current user may link to a worker. Admin Badan Usaha only sees accounts without
     * Superadmin/Admin roles that are unlinked or belong to a worker in their companies.
     *
     * @return array<int, string>
     */
    public static function assignableUserOptions(): array
    {
        $user = auth()->user();
        $managed = $user?->managedCompanyIds() ?? [];

        return User::query()
            ->when($user?->managedCompanyIds() !== null, fn (Builder $query) => $query
                ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', [SystemRole::Superadmin->value, SystemRole::Admin->value]))
                ->where(fn (Builder $scope) => $scope
                    ->where(fn (Builder $unlinked) => $unlinked->whereDoesntHave('employee')->whereDoesntHave('intern')->whereDoesntHave('freelancer'))
                    ->orWhereHas('employee', fn (Builder $worker) => $worker->whereIn('company_id', $managed))
                    ->orWhereHas('intern', fn (Builder $worker) => $worker->whereIn('company_id', $managed))
                    ->orWhereHas('freelancer', fn (Builder $worker) => $worker->whereIn('company_id', $managed))))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
