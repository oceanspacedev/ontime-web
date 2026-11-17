<?php

namespace App\Services;

use App\Enums\DataScope;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\Division;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Akun & akses of a Klien, shared by the web panel (Manajemen User) and the app. The rules come from
 * the panel (ScopesToManagedCompanies on UserResource), which read the signed-in user, so $actor is
 * always the authenticated account:
 *
 * - only assignable roles (Superadmin and PIC never; Admin only by the PIC),
 * - data access never wider than the actor's own and never outside their Klien,
 * - nobody edits their own account, and new accounts join the actor's Klien.
 */
final class ClientAccountService
{
    /**
     * Roles $actor may hand out.
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        return array_values(array_keys(UserResource::assignableRoleOptions()));
    }

    /**
     * Data access levels $actor may give, with their labels.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public function grantableScopes(User $actor): array
    {
        $isPlatformAdmin = $actor->isPlatformAdmin();

        return collect(DataScope::cases())
            ->reject(fn (DataScope $scope): bool => $scope === DataScope::All && ! DataAccessService::canGrantAccess($actor, DataScope::All))
            ->map(fn (DataScope $scope): array => [
                'value' => $scope->value,
                'label' => $scope->getLabel(),
                'description' => $scope === DataScope::All && ! $isPlatformAdmin ? 'Seluruh badan usaha milik kliennya.' : $scope->getDescription(),
            ])
            ->values()
            ->all();
    }

    /**
     * Companies $actor may give as data access "Badan Usaha".
     *
     * @return list<array{id: int, name: string}>
     */
    public function grantableCompanies(User $actor): array
    {
        $filter = DataAccessService::workerFilter($actor);

        return Company::query()
            ->when($filter !== null, fn (Builder $query) => $query->whereIn('id', $filter['companies'] === [] ? [0] : $filter['companies']))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company): array => ['id' => (int) $company->id, 'name' => $company->name])
            ->all();
    }

    /**
     * Divisions $actor may give as data access "Divisi", labelled with their company and path.
     *
     * @return list<array{id: int, company_id: int, label: string}>
     */
    public function grantableDivisions(User $actor): array
    {
        $labels = UserResource::divisionPathLabels();
        $divisions = Division::query()->whereIn('id', array_keys($labels))->get(['id', 'company_id']);

        if (! $actor->isPlatformAdmin()) {
            $divisions = $divisions->filter(fn (Division $division): bool => DataAccessService::canGrantAccess($actor, DataScope::Divisions, divisionIds: [$division->id]));
        }

        return $divisions
            ->map(fn (Division $division): array => ['id' => (int) $division->id, 'company_id' => (int) $division->company_id, 'label' => $labels[$division->id]])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * Validation rules for an account sent as plain data (the app).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array<int, mixed>>
     */
    public function rules(User $actor, array $input, ?User $account = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $account
                ? ['prohibited']
                : ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in($this->assignableRoles())],
            'data_scope' => ['required', Rule::enum(DataScope::class), $this->grantRule($actor, $input)],
            'company_ids' => ['nullable', 'array', 'required_if:data_scope,'.DataScope::Companies->value],
            'company_ids.*' => ['integer', 'distinct'],
            'division_ids' => ['nullable', 'array', 'required_if:data_scope,'.DataScope::Divisions->value],
            'division_ids.*' => ['integer', 'distinct'],
            'is_active' => $account ? ['required', 'boolean'] : ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email ini sudah dipakai akun lain.',
            'email.prohibited' => 'Email akun tidak bisa diubah.',
            'roles.required' => 'Pilih minimal satu role.',
            'roles.min' => 'Pilih minimal satu role.',
            'roles.*.in' => 'Role ini tidak bisa Anda berikan.',
            'data_scope.required' => 'Pilih tingkat akses data.',
            'data_scope.enum' => 'Tingkat akses data tidak dikenal.',
            'company_ids.required_if' => 'Pilih minimal satu badan usaha.',
            'division_ids.required_if' => 'Pilih minimal satu divisi.',
            'is_active.required' => 'Status aktif wajib diisi.',
        ];
    }

    /**
     * Create an account in the actor's Klien with a generated password, sent over WhatsApp.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, email: string, password: string, whatsapp: array<string, mixed>}
     */
    public function create(User $actor, array $data): array
    {
        $email = Str::lower(trim((string) $data['email']));
        $password = Str::password(10, symbols: false);

        $user = DB::transaction(function () use ($actor, $data, $email, $password): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($password),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $user->tenant_id = $actor->isPlatformAdmin() ? null : $actor->tenant_id;
            $user->save();

            $this->applyAccess($user, $data);

            return $user;
        });

        $whatsapp = WhatsAppNotificationService::sendAccountCredentials($user->name, $user->phone, $email, $password);

        return ['user' => $user, 'email' => $email, 'password' => $password, 'whatsapp' => $whatsapp];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $account, array $data): User
    {
        DB::transaction(function () use ($account, $data): void {
            $account->fill([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'is_active' => (bool) $data['is_active'],
            ])->save();

            $this->applyAccess($account, $data);
        });

        return $account;
    }

    /**
     * Give the account a new generated password, sign it out of the app everywhere (its tokens are
     * revoked) and send the password over WhatsApp.
     *
     * @return array{user: User, email: string, password: string, whatsapp: array<string, mixed>}
     */
    public function resetPassword(User $account): array
    {
        $password = Str::password(10, symbols: false);
        $account->forceFill(['password' => Hash::make($password)])->save();
        $account->tokens()->delete();

        $whatsapp = WhatsAppNotificationService::sendAccountCredentials($account->name, $account->phone, $account->email, $password);

        return ['user' => $account, 'email' => $account->email, 'password' => $password, 'whatsapp' => $whatsapp];
    }

    /**
     * Accounts the actor manages, for the account list (PIC: every account of the Klien).
     *
     * @return Builder<User>
     */
    public function manageableAccounts(): Builder
    {
        return UserResource::scopeToManageableAccounts(User::query(), includeUnlinked: false);
    }

    public function canManage(?User $account): bool
    {
        return UserResource::canManageAccount($account);
    }

    /**
     * Data access given to an account must stay inside the actor's own.
     *
     * @param  array<string, mixed>  $input
     */
    private function grantRule(User $actor, array $input): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($actor, $input): void {
            $scope = is_string($value) ? DataScope::tryFrom($value) : null;

            if (! $scope) {
                return;
            }

            $companyIds = $scope === DataScope::Companies ? array_filter((array) ($input['company_ids'] ?? []), 'is_numeric') : [];
            $divisionIds = $scope === DataScope::Divisions ? array_filter((array) ($input['division_ids'] ?? []), 'is_numeric') : [];

            // Picks that do not exist (or another client's, which canGrantAccess already refuses) never pass.
            $unknownDivisions = $divisionIds !== [] && Division::query()->whereIn('id', $divisionIds)->count() !== count(array_unique($divisionIds));

            if ($unknownDivisions || ! DataAccessService::canGrantAccess($actor, $scope, array_values($companyIds), array_values($divisionIds))) {
                $fail('Akses data tidak boleh lebih luas dari akses Anda sendiri.');
            }
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyAccess(User $user, array $data): void
    {
        $user->syncRoles(array_values(array_intersect((array) $data['roles'], $this->assignableRoles())));

        $scope = DataScope::from((string) $data['data_scope']);
        $user->grantDataAccess(
            $scope,
            array_map('intval', (array) ($data['company_ids'] ?? [])),
            array_map('intval', (array) ($data['division_ids'] ?? [])),
        );
    }
}
