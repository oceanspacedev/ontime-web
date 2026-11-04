<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * "Admin" (Admin Badan Usaha) manages everything inside the companies listed in company_user,
     * the same idea as the data access per badan usaha in dnd-web. Superadmin stays global.
     */
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['company_id', 'user_id']);
            $table->index('user_id');
        });

        $roles = config('permission.table_names.roles', 'roles');

        if (! DB::table($roles)->where('name', 'Admin')->where('guard_name', 'web')->exists()) {
            DB::table($roles)->insert(['name' => 'Admin', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
