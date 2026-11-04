<?php

namespace App\Enums;

/**
 * Roles the application checks by name (hasRole / hasAnyRole). Renaming or deleting one
 * would silently remove access, so they are protected from changes in the Roles page.
 */
enum SystemRole: string
{
    case Superadmin = 'Superadmin';
    /** Admin Badan Usaha: full control, limited to the companies in users' company access. */
    case Admin = 'Admin';
    case Bod = 'BOD';
    case Approver = 'Approver';
    case Employee = 'Employee';
    case Intern = 'Intern';
    case Freelancer = 'Freelancer';

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
