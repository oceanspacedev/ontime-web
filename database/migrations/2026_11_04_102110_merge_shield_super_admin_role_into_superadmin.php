<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Filament Shield created its own "super_admin" role next to the application's "Superadmin".
     * Only "Superadmin" grants anything (Gate::before), so move any holders over and remove
     * the duplicate. Shield is now configured to treat "Superadmin" as its super admin.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $roleIdColumn = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $legacyRole = DB::table($tableNames['roles'])->where('name', 'super_admin')->where('guard_name', 'web')->first();

        if (! $legacyRole) {
            return;
        }

        $superadminRole = DB::table($tableNames['roles'])->where('name', 'Superadmin')->where('guard_name', 'web')->first();

        if (! $superadminRole) {
            DB::table($tableNames['roles'])->where('id', $legacyRole->id)->update(['name' => 'Superadmin']);
        } else {
            $assignments = DB::table($tableNames['model_has_roles'])->where($roleIdColumn, $legacyRole->id)->get();

            foreach ($assignments as $assignment) {
                $moved = (array) $assignment;
                $moved[$roleIdColumn] = $superadminRole->id;

                DB::table($tableNames['model_has_roles'])->insertOrIgnore($moved);
            }

            DB::table($tableNames['model_has_roles'])->where($roleIdColumn, $legacyRole->id)->delete();
            DB::table($tableNames['role_has_permissions'])->where($roleIdColumn, $legacyRole->id)->delete();
            DB::table($tableNames['roles'])->where('id', $legacyRole->id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The duplicate role granted nothing; there is nothing meaningful to restore.
    }
};
