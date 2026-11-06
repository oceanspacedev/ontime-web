<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The "Koordinator" role runs the outlets of the brands in their data access.
     */
    public function up(): void
    {
        $roles = config('permission.table_names.roles');

        if (DB::table($roles)->where('name', 'Koordinator')->where('guard_name', 'web')->doesntExist()) {
            DB::table($roles)->insert(['name' => 'Koordinator', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $roleIdColumn = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $roleId = DB::table($tableNames['roles'])->where('name', 'Koordinator')->where('guard_name', 'web')->value('id');

        if ($roleId) {
            DB::table($tableNames['model_has_roles'])->where($roleIdColumn, $roleId)->delete();
            DB::table($tableNames['role_has_permissions'])->where($roleIdColumn, $roleId)->delete();
            DB::table($tableNames['roles'])->where('id', $roleId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
