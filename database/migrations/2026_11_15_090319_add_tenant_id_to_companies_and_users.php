<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every Badan Usaha and every account except the platform Superadmin belongs to a Klien.
     *
     * Existing data goes to one Klien, so nothing changes for the client already on the platform:
     * its Admins with data access "Semua" still see all of its Badan Usaha. A company code only has
     * to be unique inside its Klien.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
        });

        $hasData = DB::table('companies')->exists() || DB::table('users')->exists();

        if ($hasData) {
            $tenantId = DB::table('tenants')->insertGetId([
                'name' => 'Klien Utama',
                'code' => 'UTAMA',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('companies')->update(['tenant_id' => $tenantId]);
            DB::table('users')->whereNotIn('id', $this->superadminIds())->update(['tenant_id' => $tenantId]);
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->unique(['code']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }

    /**
     * @return list<int>
     */
    private function superadminIds(): array
    {
        $tableNames = config('permission.table_names');
        $roleId = DB::table($tableNames['roles'])->where('name', 'Superadmin')->where('guard_name', 'web')->value('id');

        if (! $roleId) {
            return [];
        }

        return DB::table($tableNames['model_has_roles'])
            ->where('role_id', $roleId)
            ->where('model_type', 'App\\Models\\User')
            ->pluck('model_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
};
