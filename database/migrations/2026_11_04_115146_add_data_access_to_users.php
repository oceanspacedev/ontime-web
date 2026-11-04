<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ERP-style data access per user: users.data_scope (all, companies, divisions, own) with the chosen
     * companies in company_user and divisions in division_user. Replaces the approvers mapping as the
     * source of which workers an Approver / BOD sees and approves.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('data_scope', 20)->default('own')->after('is_active');
        });

        Schema::create('division_user', function (Blueprint $table) {
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['division_id', 'user_id']);
            $table->index('user_id');
        });

        $this->migrateExistingAccess();
    }

    public function down(): void
    {
        Schema::dropIfExists('division_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('data_scope');
        });
    }

    private function migrateExistingAccess(): void
    {
        $now = now();

        // Superadmin sees everything.
        DB::table('users')->whereIn('id', $this->userIdsWithRole('Superadmin'))->update(['data_scope' => 'all']);

        // Admin Badan Usaha already has its companies in company_user.
        DB::table('users')
            ->whereIn('id', DB::table('company_user')->select('user_id'))
            ->where('data_scope', 'own')
            ->update(['data_scope' => 'companies']);

        // Approver / BOD: turn their approvers mapping into data access.
        $mappings = DB::table('approvers')
            ->whereNotIn('user_id', DB::table('users')->where('data_scope', '!=', 'own')->select('id'))
            ->get(['user_id', 'company_id', 'division_id'])
            ->groupBy('user_id');

        foreach ($mappings as $userId => $rows) {
            $companyIds = $rows->whereNull('division_id')->pluck('company_id')->filter()->unique()->values();
            $divisionIds = $rows->whereNotNull('division_id')->pluck('division_id')->unique()->values();

            if ($divisionIds->isEmpty()) {
                DB::table('users')->where('id', $userId)->update(['data_scope' => 'companies']);
                DB::table('company_user')->insertOrIgnore($companyIds->map(fn ($id): array => ['company_id' => $id, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now])->all());

                continue;
            }

            // Company-wide rows become the company's top-level divisions, which cover every sub-division.
            $divisionIds = $divisionIds->merge(
                DB::table('divisions')->whereIn('company_id', $companyIds)->whereNull('parent_id')->pluck('id')
            )->unique();

            DB::table('users')->where('id', $userId)->update(['data_scope' => 'divisions']);
            DB::table('division_user')->insertOrIgnore($divisionIds->map(fn ($id): array => ['division_id' => $id, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now])->all());
        }
    }

    /**
     * @return list<int>
     */
    private function userIdsWithRole(string $role): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', $role)
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
};
