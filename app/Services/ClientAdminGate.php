<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Division;
use App\Models\User;

/**
 * Who reaches which Klien settings from the app. Not allowed answers 403; a record outside the user's
 * reach answers 404, so another client's data is never revealed.
 */
final class ClientAdminGate
{
    /**
     * The client_admin permissions the app uses to show its Klien menus.
     *
     * @return array{is_pic: bool, can_manage_companies: bool, can_create_company: bool, can_manage_accounts: bool}
     */
    public static function permissions(User $user): array
    {
        return [
            'is_pic' => DataAccessService::isTenantOwner($user),
            'can_manage_companies' => DataAccessService::canManageCompanySettings($user),
            // The platform Superadmin adds companies from the web panel: from the app they would land outside every Klien.
            'can_create_company' => DataAccessService::canCreateCompany($user) && ! $user->isPlatformAdmin(),
            'can_manage_accounts' => DataAccessService::canManageAccounts($user),
        ];
    }

    public static function ensureManagesCompanies(User $user): void
    {
        abort_unless(DataAccessService::canManageCompanySettings($user), 403, 'Pengaturan badan usaha hanya untuk PIC atau Admin badan usaha.');
    }

    public static function ensureCreatesCompanies(User $user): void
    {
        self::ensureNotPlatformAdmin($user);
        abort_unless(DataAccessService::canCreateCompany($user), 403, 'Hanya PIC yang bisa menambah badan usaha.');
    }

    public static function ensureManagesAccounts(User $user): void
    {
        abort_unless(DataAccessService::canManageAccounts($user), 403, 'Akun & akses hanya untuk PIC atau Admin.');
    }

    /**
     * Adding accounts and companies from the app always lands in the user's Klien; the platform
     * Superadmin has none, so they do it from the web panel.
     */
    public static function ensureNotPlatformAdmin(User $user): void
    {
        abort_if($user->isPlatformAdmin(), 403, 'Superadmin mengelola klien lewat panel web.');
    }

    public static function company(User $user, int|string $id): Company
    {
        self::ensureManagesCompanies($user);
        $company = Company::query()->find($id);

        abort_unless($company && DataAccessService::canManageCompany($user, $company->id), 404, 'Badan usaha tidak ditemukan.');

        return $company;
    }

    public static function location(User $user, int|string $id): CompanyLocation
    {
        self::ensureManagesCompanies($user);
        $location = CompanyLocation::query()->find($id);

        abort_unless($location && DataAccessService::canManageCompany($user, $location->company_id), 404, 'Lokasi tidak ditemukan.');

        return $location;
    }

    public static function division(User $user, int|string $id): Division
    {
        self::ensureManagesCompanies($user);
        $division = Division::query()->find($id);

        abort_unless($division && DataAccessService::canManageCompany($user, $division->company_id), 404, 'Divisi tidak ditemukan.');

        return $division;
    }

    /**
     * An account the user may edit. Another client's account is not found; their own account or one
     * of their Klien beyond their reach (an Admin for a non-PIC Admin) is refused.
     */
    public static function account(User $user, int|string $id): User
    {
        self::ensureManagesAccounts($user);
        $account = User::query()->find($id);

        $inReach = $account && ($user->isPlatformAdmin() || (DataAccessService::sameTenant($user, $account) && ! $account->isPlatformAdmin()));

        abort_unless($inReach, 404, 'Akun tidak ditemukan.');
        abort_if($account->is($user), 403, 'Anda tidak bisa mengubah akun sendiri.');
        abort_unless(app(ClientAccountService::class)->canManage($account), 403, 'Anda tidak bisa mengubah akun ini.');

        return $account;
    }

    /**
     * An account the user may read: one in their account list, or one they may edit. Anything else,
     * their own account included, is not found.
     */
    public static function readableAccount(User $user, int|string $id): User
    {
        self::ensureManagesAccounts($user);
        $accounts = app(ClientAccountService::class);
        $account = User::query()->find($id);

        $inReach = $account
            && ! $account->is($user)
            && ($user->isPlatformAdmin() || (DataAccessService::sameTenant($user, $account) && ! $account->isPlatformAdmin()))
            && ($accounts->manageableAccounts()->whereKey($account->getKey())->exists() || $accounts->canManage($account));

        abort_unless($inReach, 404, 'Akun tidak ditemukan.');

        return $account;
    }
}
