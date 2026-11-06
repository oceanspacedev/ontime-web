<?php

namespace App\Enums;

/**
 * Roles the application checks by name (hasRole / hasAnyRole). Renaming or deleting one
 * would silently remove access, so they are protected from changes in the Roles page.
 */
enum SystemRole: string
{
    case Superadmin = 'Superadmin';
    /** Admin: manages workers and settings inside their own data access (see DataAccessService). */
    case Admin = 'Admin';
    /** Koordinator: runs the outlets of the brands in their data access (freelancers, shifts, schedules), no company settings. */
    case Koordinator = 'Koordinator';
    case Bod = 'BOD';
    case Approver = 'Approver';
    case Employee = 'Employee';
    case Intern = 'Intern';
    case Freelancer = 'Freelancer';

    /**
     * Roles that manage workers. Only Superadmin may grant them or manage their accounts.
     *
     * @return list<string>
     */
    public static function managerNames(): array
    {
        return [self::Superadmin->value, self::Admin->value, self::Koordinator->value];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function isSystemRole(string $name): bool
    {
        return in_array($name, self::names(), true);
    }
}
