<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the PIC role, and take the platform Superadmin out of every client's approvals: stages
     * set to role Superadmin, in flows and in requests still waiting, go to the client's Admin.
     */
    public function up(): void
    {
        $roles = config('permission.table_names.roles');

        if (DB::table($roles)->where('name', 'PIC')->where('guard_name', 'web')->doesntExist()) {
            DB::table($roles)->insert(['name' => 'PIC', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('approval_flows')
            ->where('approver_type', 'role')
            ->where('approver_role', 'Superadmin')
            ->update(['approver_role' => 'Admin', 'name' => DB::raw("REPLACE(name, 'Superadmin', 'Admin')")]);

        DB::table('approval_request_steps')
            ->where('status', 'pending')
            ->where('approver_type', 'role')
            ->where('approver_role', 'Superadmin')
            ->update(['approver_role' => 'Admin', 'step_name' => DB::raw("REPLACE(step_name, 'Superadmin', 'Admin')")]);

        // Stages naming a Superadmin account directly go to the client's Admin too.
        $superadminIds = $this->superadminIds();

        if ($superadminIds !== []) {
            DB::table('approval_flows')
                ->where('approver_type', 'user')
                ->whereIn('user_id', $superadminIds)
                ->update(['approver_type' => 'role', 'approver_role' => 'Admin', 'user_id' => null]);

            DB::table('approval_request_steps')
                ->where('status', 'pending')
                ->where('approver_type', 'user')
                ->whereIn('user_id', $superadminIds)
                ->update(['approver_type' => 'role', 'approver_role' => 'Admin', 'user_id' => null]);
        }
    }

    /**
     * @return list<int>
     */
    private function superadminIds(): array
    {
        $tableNames = config('permission.table_names');
        $roleId = DB::table($tableNames['roles'])->where('name', 'Superadmin')->where('guard_name', 'web')->value('id');

        return $roleId
            ? DB::table($tableNames['model_has_roles'])->where('role_id', $roleId)->where('model_type', 'App\\Models\\User')->pluck('model_id')->map(fn ($id): int => (int) $id)->all()
            : [];
    }

    /**
     * The rerouted stages stay with the Admin: there is no record of which ones were Superadmin's.
     */
    public function down(): void
    {
        $roles = config('permission.table_names.roles');
        $roleId = DB::table($roles)->where('name', 'PIC')->where('guard_name', 'web')->value('id');

        if ($roleId) {
            DB::table(config('permission.table_names.model_has_roles'))->where('role_id', $roleId)->delete();
            DB::table($roles)->where('id', $roleId)->delete();
        }
    }
};
